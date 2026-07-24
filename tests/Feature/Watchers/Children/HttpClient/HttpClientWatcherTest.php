<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\HttpClient;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Guzzle\GuzzleHandlerFactory;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\HttpClientWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;
use Throwable;

class HttpClientWatcherTest extends BaseChildWatcherTestCase
{
    public function testDoesNotLeakTrackedRequestsOnSuccess(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        $watcher = $this->bindSharedWatcher();

        dispatch($this->getSuccessCallback());

        // every tracked request must be released once its trace is stopped,
        // otherwise long-running workers leak one entry per outbound request.
        self::assertSame([], $this->getTrackedRequests($watcher));
    }

    public function testDoesNotLeakTrackedRequestsOnFailure(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        $watcher = $this->bindSharedWatcher();

        dispatch(static function (): void {
            $handlerStack = app(GuzzleHandlerFactory::class)->prepareHandler(
                formatters: new RequestDataFormatters(),
                handlerStack: HandlerStack::create(
                    new MockHandler([
                        new ConnectException('boom', new Request('POST', 'https://example.test/alpha')),
                    ])
                )
            );

            $client = new Client([
                'handler'     => $handlerStack,
                'http_errors' => false,
            ]);

            try {
                $client->request('post', 'https://example.test/alpha');
            } catch (Throwable) {
                // the request fails on purpose; the watcher must still release its entry
            }
        });

        self::assertSame([], $this->getTrackedRequests($watcher));
    }

    public function testParentIsJob(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(
            $this->getSuccessCallback()
        );

        self::assertEquals(
            4,
            $this->dispatcher->totalCount()
        );

        $creating = $this->dispatcher->findCreating(
            type: $this->getTraceType(),
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );

        $creating = $this->dispatcher->findUpdating(
            traceId: $creating[0]->traceId,
            status: TraceStatusEnum::Success,
        );

        self::assertCount(
            1,
            $creating
        );
    }

    protected function getTraceType(): string
    {
        return 'http-client';
    }

    protected function getWatcherClass(): string
    {
        return HttpClientWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return static function (): void {
            $handler = new MockHandler([
                new Response(
                    status: 200,
                    headers: ['Content-Type' => 'application/json'],
                    body: json_encode(['ok' => true], JSON_THROW_ON_ERROR)
                ),
            ]);

            $handlerStack = HandlerStack::create($handler);

            /** @var GuzzleHandlerFactory $factory */
            $factory = app(GuzzleHandlerFactory::class);

            $handlerStack = $factory->prepareHandler(
                formatters: new RequestDataFormatters(),
                handlerStack: $handlerStack
            );

            $client = new Client([
                'handler'     => $handlerStack,
                'http_errors' => false,
            ]);

            $client->request(
                'post',
                'https://example.test/alpha',
                [
                    'json' => [
                        'foo' => 'bar',
                    ],
                ]
            );
        };
    }

    protected function assertSuccess(TraceCreateObject $creatingTrace, TraceUpdateObject $updatingTrace): void
    {
        // no action
    }

    /**
     * Bind a single HttpClientWatcher instance so the Guzzle handler (resolved
     * inside the dispatched job) and the test inspect the same object, without
     * making the watcher a singleton in production.
     */
    private function bindSharedWatcher(): HttpClientWatcher
    {
        $watcher = app(HttpClientWatcher::class);

        $this->getApp()->instance(HttpClientWatcher::class, $watcher);

        return $watcher;
    }

    /**
     * @return array<string, array{trace_id: string, started_at: mixed}>
     */
    private function getTrackedRequests(HttpClientWatcher $watcher): array
    {
        $property = (new ReflectionClass($watcher))->getProperty('requests');
        $property->setAccessible(true);

        /** @var array<string, array{trace_id: string, started_at: mixed}> $requests */
        $requests = $property->getValue($watcher);

        return $requests;
    }
}
