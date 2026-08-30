<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Concurrency;

use Fiber;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Events\RequestHandling;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

/**
 * Two requests in flight in one process, neither of them related to the other.
 *
 * With the parent id and the watcher's bookkeeping held per process, the second
 * request read the first one's id and filed itself underneath it. On real traffic that
 * showed up as a quarter to a half of all request traces having another request trace
 * as their parent.
 */
class InterleavedRequestsTest extends BaseConcurrencyTestCase
{
    /**
     * @var array<string, string|null>
     */
    private array $parentIdsSeen = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->parentIdsSeen = [];

        $this->registerWatcher(RequestWatcher::class, null);
    }

    public function testNeitherRequestIsFiledUnderTheOther(): void
    {
        $this->interleave([
            fn() => $this->handleRequest('first', '/slogger/first'),
            fn() => $this->handleRequest('second', '/slogger/second'),
        ]);

        $creating = $this->dispatcher->findCreating(type: 'request', isParent: true);

        self::assertCount(2, $creating, 'both requests must have produced a trace');

        $traceIds = array_map(static fn($trace) => $trace->traceId, $creating);

        foreach ($creating as $trace) {
            self::assertNull(
                $trace->parentTraceId,
                'a request nobody called into is a root trace'
            );
        }

        self::assertCount(2, array_unique($traceIds), 'two units, two traces');

        // both closed, as themselves

        foreach ($traceIds as $traceId) {
            self::assertCount(
                1,
                $this->dispatcher->findUpdating(traceId: $traceId, status: TraceStatusEnum::Success)
            );
        }

        // and nothing was swept as collateral
        self::assertSame([], $this->dispatcher->findUpdating(tag: Processor::INTERRUPTED_TAG));
    }

    /**
     * What a job published mid-request carries with it. The payload closure reads the
     * container at the moment of publishing, so an id shared by the process would send
     * the job off to join a stranger's trace on another machine.
     */
    public function testEachRequestSeesItsOwnIdWhileBothAreOpen(): void
    {
        $this->interleave([
            fn() => $this->handleRequest('first', '/slogger/first'),
            fn() => $this->handleRequest('second', '/slogger/second'),
        ]);

        self::assertCount(2, $this->parentIdsSeen);

        self::assertNotNull($this->parentIdsSeen['first']);
        self::assertNotNull($this->parentIdsSeen['second']);

        self::assertNotSame(
            $this->parentIdsSeen['first'],
            $this->parentIdsSeen['second'],
            'each request in flight has a parent id of its own'
        );

        $started = array_map(
            static fn($trace) => $trace->traceId,
            $this->dispatcher->findCreating(type: 'request', isParent: true)
        );

        self::assertContains($this->parentIdsSeen['first'], $started);
        self::assertContains($this->parentIdsSeen['second'], $started);
    }

    /**
     * Started, suspended while the other request runs, and only then handled.
     */
    private function handleRequest(string $name, string $uri): void
    {
        $request = Request::create($uri);

        event(new RequestHandling(request: $request, parentTraceId: null));

        Fiber::suspend();

        $this->parentIdsSeen[$name] = $this->getApp()
            ->make(TraceIdContainer::class)
            ->getParentTraceId();

        Fiber::suspend();

        event(new RequestHandled($request, new Response('ok')));
    }
}
