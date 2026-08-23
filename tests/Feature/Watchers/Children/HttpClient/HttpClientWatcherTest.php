<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\HttpClient;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Guzzle\GuzzleHandlerFactory;
use SLoggerLaravel\Helpers\BodyDecoder;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Processor;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\HttpClientWatcher;
use SLoggerLaravel\Watchers\OpenTraces;
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
        self::assertSame(0, $this->countTrackedRequests($watcher));
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

        self::assertSame(0, $this->countTrackedRequests($watcher));
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

        self::assertSame(0, $this->countTrackedRequests($watcher));
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
        self::assertSame(0, $this->countTrackedRequests($watcher));
    }

    public function testANonSeekableRequestBodyDoesNotBreakTheTrace(): void
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

            // an upload from a pipe: rewind() throws, and the trace used to die with
            // it - reported as failed and swept as interrupted, with no data at all
            $client->request('POST', 'https://example.test/upload', [
                'body' => new NoSeekStream(Psr7Utils::streamFor('{"payload":"x"}')),
            ]);
        });

        $creating = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);

        self::assertSame(TraceStatusEnum::Success->value, $updating[0]->status);
        self::assertNotContains(Processor::INTERRUPTED_TAG, $updating[0]->tags ?? []);

        self::assertSame(
            ['__skipped' => 'non_seekable_body'],
            ($updating[0]->data ?? [])['request']['payload']
        );
    }

    public function testALargeRequestBodyIsDescribedRatherThanCopied(): void
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

            // telemetry must not double the memory an upload needs
            $client->request('POST', 'https://example.test/upload', [
                'body' => str_repeat('a', 1000000 + 1),
            ]);
        });

        $creating = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        $payload = ($updating[0]->data ?? [])['request']['payload'];

        self::assertArrayHasKey('__cleaned', $payload);
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

    public function testTheOutboundHeaderCarriesTheCallsOwnTraceId(): void
    {
        // no enclosing trace on purpose: the header must still go out, because the
        // call's own trace exists either way
        $mock = new MockHandler([new Response(200)]);

        $handlerStack = app(GuzzleHandlerFactory::class)->prepareHandler(
            formatters: new RequestDataFormatters(),
            handlerStack: HandlerStack::create($mock)
        );

        $client = new Client([
            'handler'     => $handlerStack,
            'http_errors' => false,
        ]);

        $client->request('get', 'https://example.test/alpha');

        $sent = $mock->getLastRequest();

        self::assertNotNull($sent);

        $creating = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(1, $creating);

        $headerKey = app(WatchersConfig::class)->requestsHeaderParentTraceIdKey();

        self::assertNotNull($headerKey);

        // the call's own trace, not the one enclosing it: the callee hangs its trace
        // under this call, and the header is sent whether or not anything encloses it
        self::assertSame($creating[0]->traceId, $sent->getHeader($headerKey)[0] ?? null);
    }

    public function testAnXmlCallIsRecordedInBothDirections(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(static function (): void {
            $handlerStack = app(GuzzleHandlerFactory::class)->prepareHandler(
                formatters: new RequestDataFormatters(),
                handlerStack: HandlerStack::create(
                    new MockHandler([
                        new Response(
                            status: 200,
                            headers: ['Content-Type' => 'application/xml'],
                            body: '<result><api_token>sk-live-response</api_token><page>2</page></result>'
                        ),
                    ])
                )
            );

            $client = new Client([
                'handler'     => $handlerStack,
                'http_errors' => false,
            ]);

            // a SOAP call: neither direction used to reach the trace, because both
            // bodies were run through json_decode and an XML one gave []
            $client->request('post', 'https://example.test/soap', [
                'headers' => ['Content-Type' => 'application/xml'],
                'body'    => '<envelope><password>hunter2</password><amount>100</amount></envelope>',
            ]);
        });

        $creating = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(1, $creating);

        $data = ($this->dispatcher->findUpdating(traceId: $creating[0]->traceId)[0]->data ?? []);

        self::assertStringContainsString(
            '<password>hunter2</password>',
            $data['request']['payload'][BodyDecoder::XML_KEY]
        );

        self::assertStringContainsString(
            '<api_token>sk-live-response</api_token>',
            $data['response']['body'][BodyDecoder::XML_KEY]
        );

        $masked = app(TraceDataMasker::class)->mask($data);

        self::assertStringContainsString(
            '<password>' . MaskHelper::FULL_MASK . '</password>',
            $masked['request']['payload'][BodyDecoder::XML_KEY]
        );

        self::assertStringContainsString(
            '<api_token>' . MaskHelper::FULL_MASK . '</api_token>',
            $masked['response']['body'][BodyDecoder::XML_KEY]
        );

        // and what matched nothing survives in both
        self::assertStringContainsString('<amount>100</amount>', $masked['request']['payload'][BodyDecoder::XML_KEY]);
        self::assertStringContainsString('<page>2</page>', $masked['response']['body'][BodyDecoder::XML_KEY]);
    }

    public function testCredentialsInAUrlNeverReachATag(): void
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

            $client->request('get', 'https://alice:hunter2@example.test/v1/me');
        });

        $creating = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        // a tag is never masked, so the password must not be in the url at all
        self::assertSame(['https://example.test/v1/me'], $updating[0]->tags);
        self::assertSame('https://example.test/v1/me', $creating[0]->data['uri']);
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
        // the watcher is a singleton in production, so this only names the instance
        // the Guzzle handler will resolve anyway
        return app(HttpClientWatcher::class);
    }

    private function countTrackedRequests(HttpClientWatcher $watcher): int
    {
        $property = (new ReflectionClass($watcher))->getProperty('openRequests');

        /** @var OpenTraces $openRequests */
        $openRequests = $property->getValue($watcher);

        return $openRequests->count();
    }
}
