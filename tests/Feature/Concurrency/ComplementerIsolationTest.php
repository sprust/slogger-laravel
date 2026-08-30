<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Concurrency;

use Fiber;
use Illuminate\Support\Carbon;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Helpers\TraceDataComplementer;
use SLoggerLaravel\Processor;

/**
 * `slogger()->add('user_id', ...)` is what a request says about itself, and the next
 * request says something else. Held per process, one request's user was written onto
 * another request's traces, and the first one to finish wiped the values of everybody
 * still running.
 */
class ComplementerIsolationTest extends BaseConcurrencyTestCase
{
    public function testEachUnitCarriesItsOwnValues(): void
    {
        $processor    = $this->getApp()->make(Processor::class);
        $complementer = $this->getApp()->make(TraceDataComplementer::class);

        $this->interleave([
            // finishes first, and its end of unit of work must not empty the other one
            fn() => $this->runUnit($processor, $complementer, 'first', 1, suspensions: 1),
            fn() => $this->runUnit($processor, $complementer, 'second', 2, suspensions: 3),
        ]);

        foreach (['first' => 1, 'second' => 2] as $tag => $userId) {
            $creating = $this->dispatcher->findCreating(type: 'command', tag: $tag, isParent: true);

            self::assertCount(1, $creating);

            self::assertSame(
                $userId,
                $creating[0]->data[TraceDataComplementer::ADDITIONAL_KEY]['user_id'] ?? null,
                "the [$tag] trace must carry its own user"
            );
        }
    }

    private function runUnit(
        Processor $processor,
        TraceDataComplementer $complementer,
        string $tag,
        int $userId,
        int $suspensions
    ): void {
        $complementer->add('user_id', $userId);

        Fiber::suspend();

        $traceId = $processor->startAndGetTraceId(
            type: 'command',
            tags: [$tag],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null,
        );

        for ($i = 0; $i < $suspensions; $i++) {
            Fiber::suspend();
        }

        $processor->stop(
            traceId: $traceId,
            status: TraceStatusEnum::Success->value,
            tags: null,
            data: null,
            duration: 1.0,
            parentLoggedAt: Carbon::now(),
        );
    }
}
