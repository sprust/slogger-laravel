<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Traces;

use Illuminate\Support\Carbon;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Helpers\TraceDataComplementer;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;

/**
 * The default resolver keeps one scope for the whole process, so a `queue:work`
 * worker runs every job through the same object. What a unit of work leaves in it
 * is what the next unit starts with.
 */
class UnitOfWorkStateTest extends BaseWatcherTestCase
{
    public function testOneUnitsAdditionalValuesDoNotReachTheNextOne(): void
    {
        $complementer = $this->getApp()->make(TraceDataComplementer::class);

        $this->runUnitOfWork(static function () use ($complementer): void {
            $complementer->add('user_id', 4242);
            $complementer->add('customer_email', 'john.doe@example.com');
        });

        $second = $this->runUnitOfWork(static fn() => null);

        // one job's user and one job's customer, stamped on an unrelated job: this is
        // what holding the values anywhere but on the unit of work looked like
        self::assertArrayNotHasKey(TraceDataComplementer::ADDITIONAL_KEY, $second);
    }

    public function testAValueIsStillThereForTheRestOfItsOwnUnit(): void
    {
        $complementer = $this->getApp()->make(TraceDataComplementer::class);

        $data = $this->runUnitOfWork(static function () use ($complementer): void {
            $complementer->add('user_id', 4242);
        });

        self::assertSame(['user_id' => 4242], $data[TraceDataComplementer::ADDITIONAL_KEY]);
    }

    public function testACallbackSurvivesTheUnitItWasRegisteredIn(): void
    {
        $complementer = $this->getApp()->make(TraceDataComplementer::class);

        $calls = 0;

        // registered once - in a service provider, say - and re-evaluated per trace
        $complementer->add('request_id', function () use (&$calls): int {
            return ++$calls;
        });

        $first  = $this->runUnitOfWork(static fn() => null);
        $second = $this->runUnitOfWork(static fn() => null);

        // still registered in the second unit, and evaluated afresh there rather
        // than repeating what it returned in the first
        self::assertGreaterThan(
            $first[TraceDataComplementer::ADDITIONAL_KEY]['request_id'],
            $second[TraceDataComplementer::ADDITIONAL_KEY]['request_id']
        );
    }

    public function testAUnitsOwnValueWinsOverAProcessWideCallback(): void
    {
        $complementer = $this->getApp()->make(TraceDataComplementer::class);

        $complementer->add('tenant', static fn(): string => 'default');

        $data = $this->runUnitOfWork(static function () use ($complementer): void {
            $complementer->add('tenant', 'acme');
        });

        self::assertSame('acme', $data[TraceDataComplementer::ADDITIONAL_KEY]['tenant']);

        // and the rule is back in force once that unit is over
        $next = $this->runUnitOfWork(static fn() => null);

        self::assertSame('default', $next[TraceDataComplementer::ADDITIONAL_KEY]['tenant']);
    }

    public function testTheRootTracesOwnFinalUpdateCarriesTheAddedValues(): void
    {
        $complementer = $this->getApp()->make(TraceDataComplementer::class);

        $traceId = $this->processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null,
        );

        $complementer->add('user_id', 4242);

        // the watcher closing a request or a job hands over its own data here, and
        // this is the only update the root trace ever gets
        $this->processor->stop(
            traceId: $traceId,
            status: TraceStatusEnum::Success->value,
            tags: null,
            data: ['response_status' => 200],
            duration: 1.0,
            parentLoggedAt: Carbon::now(),
        );

        $updates = $this->dispatcher->findUpdating(traceId: $traceId);

        self::assertCount(1, $updates);

        // ending the unit of work before this update rather than after it is what
        // left the request's and the job's own trace - and only that one - without
        // the values the application had added for it
        self::assertSame(
            ['user_id' => 4242],
            $updates[0]->data[TraceDataComplementer::ADDITIONAL_KEY] ?? null
        );
    }

    /**
     * Runs one parent trace start to finish and returns the data of the child trace
     * recorded inside it - which is where the complementer's additions land.
     *
     * @param callable(): void $inside
     *
     * @return array<string, mixed>
     */
    private function runUnitOfWork(callable $inside): array
    {
        $this->dispatcher->flush();

        $traceId = $this->processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null,
        );

        $inside();

        $this->processor->push(
            type: 'log',
            status: TraceStatusEnum::Success->value,
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
        );

        $this->processor->stop(
            traceId: $traceId,
            status: TraceStatusEnum::Success->value,
            tags: null,
            data: null,
            duration: 1.0,
            parentLoggedAt: Carbon::now(),
        );

        $pushed = $this->dispatcher->findCreating(type: 'log');

        self::assertCount(1, $pushed);

        return $pushed[0]->data;
    }
}
