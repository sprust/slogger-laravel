<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\HttpClient;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Guzzle\GuzzleHandlerFactory;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\HttpClientWatcher;
use SLoggerLaravel\Processor;
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

        $this->assertSuccess($creating[0]);

        $updating = $this->dispatcher->findUpdating(
            traceId: $creating[0]->traceId,
            status: TraceStatusEnum::Success,
        );

        self::assertCount(
            1,
            $updating
        );

        $updatedData = $updating[0]->data ?? [];

        self::assertSame(200, $updatedData['response']['status_code']);
        self::assertSame(['ok' => true], $updatedData['response']['body']);
        self::assertSame(['foo' => 'bar'], $updatedData['request']['payload']);
    }

    public function testConcurrentRequestsDoNotInterruptEachOther(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        $this->bindSharedWatcher();

        dispatch(static function (): void {
            /** @var HttpClientWatcher $watcher */
            $watcher = app(HttpClientWatcher::class);

            $formatters = new RequestDataFormatters();

            // `Http::pool()` starts several requests before any of them answers, and
            // they answer in whatever order the remote services happen to reply
            $alpha = $watcher->handleRequest(new Request('POST', 'https://example.test/alpha'));
            $beta  = $watcher->handleRequest(new Request('POST', 'https://example.test/beta'));

            $watcher->handleResponse($alpha, [], new Response(200), $formatters);
            $watcher->handleResponse($beta, [], new Response(200), $formatters);
        });

        $creating = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(2, $creating);

        foreach ($creating as $trace) {
            $updating = $this->dispatcher->findUpdating(traceId: $trace->traceId);

            self::assertCount(1, $updating);

            // neither request may be closed as a casualty of the other one finishing
            self::assertSame(TraceStatusEnum::Success->value, $updating[0]->status);

            self::assertNotContains(
                Processor::INTERRUPTED_TAG,
                $updating[0]->tags ?? []
            );
        }

        // both are children of the job, not of each other
        $jobTrace = $this->dispatcher->findCreating(type: 'job', isParent: true);

        self::assertCount(1, $jobTrace);

        self::assertCount(
            2,
            $this->dispatcher->findCreating(
                parentTraceId: $jobTrace[0]->traceId,
                type: 'http-client',
            )
        );
    }

    public function testRequestsAreNotSerializedByTracing(): void
    {
        $watcher = $this->bindSharedWatcher();

        $handlerStack = app(GuzzleHandlerFactory::class)->prepareHandler(
            formatters: new RequestDataFormatters(),
            handlerStack: HandlerStack::create(
                new MockHandler([new Response(200), new Response(200)])
            )
        );

        $client = new Client([
            'handler'     => $handlerStack,
            'http_errors' => false,
        ]);

        $promises = [
            $client->requestAsync('GET', 'https://example.test/alpha'),
            $client->requestAsync('GET', 'https://example.test/beta'),
        ];

        // both requests are in flight. tracing must not have finished either of them
        // yet - waiting inside the middleware used to complete each request before the
        // next one was even started, turning Http::pool() into a serial loop
        self::assertCount(2, $this->dispatcher->findCreating(type: 'http-client'));
        self::assertCount(0, $this->dispatcher->findUpdating());

        Utils::settle($promises)->wait();

        self::assertCount(2, $this->dispatcher->findUpdating());

        self::assertSame([], $this->getTrackedRequests($watcher));
    }

    public function testDoesNotLeakTrackedRequestsSweptByTheProcessor(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        $watcher = $this->bindSharedWatcher();

        dispatch(static function (): void {
            $handlerStack = app(GuzzleHandlerFactory::class)->prepareHandler(
                formatters: new RequestDataFormatters(),
                handlerStack: HandlerStack::create(new MockHandler([new Response(200)]))
            );

            $client = new Client([
                'handler'     => $handlerStack,
                'http_errors' => false,
            ]);

            // started and never waited for: the promise never settles, so neither
            // response hook ever runs and the processor's sweep closes the trace
            $client->requestAsync('GET', 'https://example.test/alpha');
        });

        // the job is over, so the sweep has run
        self::assertCount(
            1,
            $this->dispatcher->findUpdating(tag: Processor::INTERRUPTED_TAG)
        );

        // and it told the watcher, which would otherwise keep the entry forever
        self::assertSame([], $this->getTrackedRequests($watcher));
    }

    public function testTheQueryStringIsCarriedAsDataAndNotAsATag(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(static function (): void {
            $handlerStack = app(GuzzleHandlerFactory::class)->prepareHandler(
                formatters: new RequestDataFormatters(),
                handlerStack: HandlerStack::create(new MockHandler([new Response(200)]))
            );

            $client = new Client([
                'handler'     => $handlerStack,
                'http_errors' => false,
            ]);

            $client->request('get', 'https://example.test/alpha?page=2&api_token=tok-secret');
        });

        $creating = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(1, $creating);

        $data = $creating[0]->data;

        // nothing masks a url, so the query string does not travel in one
        self::assertSame('https://example.test/alpha', $data['uri']);
        self::assertSame('tok-secret', $data['query']['api_token']);
        self::assertSame('page=2&api_token=tok-secret', $data['query_string']);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);
        self::assertSame(['https://example.test/alpha'], $updating[0]->tags);
    }

    public function testTheQueryStringIsMaskedOnTheWayOut(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(static function (): void {
            $handlerStack = app(GuzzleHandlerFactory::class)->prepareHandler(
                formatters: new RequestDataFormatters(),
                handlerStack: HandlerStack::create(new MockHandler([new Response(200)]))
            );

            $client = new Client([
                'handler'     => $handlerStack,
                'http_errors' => false,
            ]);

            $client->request('get', 'https://example.test/alpha?page=2&api_token=tok-secret');
        });

        $creating = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(1, $creating);

        $masked = app(TraceDataMasker::class)->mask($creating[0]->data);

        $parameters = [];

        parse_str($masked['query_string'], $parameters);

        self::assertSame('2', $parameters['page']);
        self::assertSame(MaskHelper::FULL_MASK, $parameters['api_token']);
        self::assertSame(MaskHelper::FULL_MASK, $masked['query']['api_token']);
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

    protected function assertSuccess(TraceCreateObject $creatingTrace): void
    {
        $data = $creatingTrace->data;

        // the start of an outbound request knows the url and the method, nothing more
        self::assertSame('https://example.test/alpha', $data['uri']);
        self::assertSame('POST', $data['method']);
        self::assertSame([], $data['query']);
        self::assertNull($data['query_string']);
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

        // setUp() registered a different instance, so its sweep callback points at an
        // object nothing else uses; register the shared one the handler will resolve
        $watcher->register(null);

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
