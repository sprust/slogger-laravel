<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Concurrency;

use Fiber;
use Illuminate\Support\Carbon;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Processor;

/**
 * A detached trace is an outbound call kept off the stack, because several are in
 * flight at once and they come back in no order - what `Http::pool()` produces.
 *
 * The map of them was held per process, and two of the sweeps walk all of it: closing
 * a unit of work therefore reached into the outbound calls of every other unit running
 * beside it and failed them as interrupted while they were still waiting for an answer.
 */
class DetachedTracesIsolationTest extends BaseConcurrencyTestCase
{
    public function testClosingOneUnitLeavesAnothersOutboundCallAlone(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $outboundTraceId = null;

        $this->interleave([
            // an outbound call with nothing around it: no owner to name it, which is
            // the case stopOwnerlessDetached() answers
            static function () use ($processor, &$outboundTraceId): void {
                $outboundTraceId = $processor->startAndGetDetachedTraceId(
                    type: 'http-client',
                    tags: [],
                    data: [],
                    loggedAt: Carbon::now(),
                );

                // still waiting for the response while the neighbour finishes: twice,
                // so the unit beside it closes first and its sweeps run while this
                // call is still open
                Fiber::suspend();
                Fiber::suspend();

                $processor->stop(
                    traceId: $outboundTraceId,
                    status: TraceStatusEnum::Success->value,
                    tags: null,
                    data: null,
                    duration: 1.0,
                    parentLoggedAt: Carbon::now(),
                );
            },
            // a whole unit of work beside it, start to finish
            static function () use ($processor): void {
                $traceId = $processor->startAndGetTraceId(
                    type: 'command',
                    tags: ['neighbour'],
                    data: [],
                    loggedAt: Carbon::now(),
                    customParentTraceId: null,
                );

                Fiber::suspend();

                $processor->stop(
                    traceId: $traceId,
                    status: TraceStatusEnum::Success->value,
                    tags: null,
                    data: null,
                    duration: 1.0,
                    parentLoggedAt: Carbon::now(),
                );
            },
        ]);

        self::assertIsString($outboundTraceId);

        self::assertSame(
            [],
            $this->dispatcher->findUpdating(tag: Processor::INTERRUPTED_TAG),
            'a call still in flight elsewhere must not be swept by a unit closing here'
        );

        // and it closed once, as itself
        self::assertCount(
            1,
            $this->dispatcher->findUpdating(
                traceId: $outboundTraceId,
                status: TraceStatusEnum::Success
            )
        );
    }
}
