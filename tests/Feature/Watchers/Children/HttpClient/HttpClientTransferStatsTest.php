<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\HttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Guzzle\GuzzleHandlerFactory;
use SLoggerLaravel\Processor;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Children\HttpClientWatcher;
use Throwable;

/**
 * How long the call took to reach the server, as the handler measured it. The stats
 * arrive through Guzzle's `on_stats`, which the handler calls before the promise
 * settles - so they are on hand when the trace is closed.
 */
class HttpClientTransferStatsTest extends BaseWatcherTestCase
{
    private const HANDLER_STATS = [
        'namelookup_time'    => 0.0012344,
        'connect_time'       => 0.021,
        'appconnect_time'    => 0.063,
        'pretransfer_time'   => 0.0631,
        'starttransfer_time' => 0.25,
        'total_time'         => 0.3,
        'primary_ip'         => '93.184.216.34',
        'http_code'          => 200,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher(HttpClientWatcher::class, null);
    }

    public function testTheConnectionTimingsAreRecorded(): void
    {
        $client = $this->makeClient(self::curlLikeHandler(self::HANDLER_STATS, new Response(200)));

        $client->request('GET', 'https://example.test/alpha');

        $data = $this->findStoppedData();

        self::assertSame(
            [
                'namelookup_time'    => 0.001234,
                'connect_time'       => 0.021,
                'appconnect_time'    => 0.063,
                'pretransfer_time'   => 0.0631,
                'starttransfer_time' => 0.25,
                'total_time'         => 0.3,
                'primary_ip'         => '93.184.216.34',
            ],
            $data['transfer']
        );
    }

    public function testTheConnectionTimingsAreRecordedForAFailedCall(): void
    {
        // a server that accepted the connection and then never answered: the timings
        // are what tells it apart from one that could not be reached
        $client = $this->makeClient(
            self::curlLikeHandler(
                ['connect_time' => 0.02, 'total_time' => 5.0],
                new ConnectException('timed out', new Request('GET', 'https://example.test/alpha'))
            )
        );

        try {
            $client->request('GET', 'https://example.test/alpha');
        } catch (ConnectException) {
            // on purpose
        }

        $data = $this->findStoppedData(TraceStatusEnum::Failed);

        self::assertArrayHasKey('exception', $data);
        self::assertSame(['connect_time' => 0.02, 'total_time' => 5.0], $data['transfer']);
    }

    public function testNothingIsRecordedWhenTheHandlerMeasuredNothing(): void
    {
        // the stream handler and a mock report no handler stats
        $client = $this->makeClient(new MockHandler([new Response(200)]));

        $client->request('GET', 'https://example.test/alpha');

        self::assertArrayNotHasKey('transfer', $this->findStoppedData());
    }

    public function testTheCallersOwnOnStatsStillRuns(): void
    {
        $client = $this->makeClient(self::curlLikeHandler(self::HANDLER_STATS, new Response(200)));

        $received = null;

        $client->request('GET', 'https://example.test/alpha', [
            'on_stats' => static function (TransferStats $stats) use (&$received): void {
                $received = $stats;
            },
        ]);

        self::assertInstanceOf(TransferStats::class, $received);
        self::assertSame(self::HANDLER_STATS, $received->getHandlerStats());

        self::assertArrayHasKey('transfer', $this->findStoppedData());
    }

    public function testLaravelsHttpClientKeepsItsTransferStats(): void
    {
        // Laravel fills $response->transferStats from an on_stats of its own
        $response = Http::setHandler(
            $this->prepareHandler(self::curlLikeHandler(self::HANDLER_STATS, new Response(200)))
        )->get('https://example.test/alpha');

        self::assertSame(self::HANDLER_STATS, $response->handlerStats());

        self::assertSame(0.021, $this->findStoppedData()['transfer']['connect_time']);
    }

    public function testConcurrentCallsKeepTheirOwnTimings(): void
    {
        $handler = static function (RequestInterface $request, array $options): PromiseInterface {
            $isAlpha = str_ends_with($request->getUri()->getPath(), 'alpha');

            return self::curlLikeHandler(
                ['connect_time' => $isAlpha ? 0.1 : 0.2, 'primary_ip' => $isAlpha ? '10.0.0.1' : '10.0.0.2'],
                new Response(200)
            )($request, $options);
        };

        $client = $this->makeClient($handler);

        $promises = [
            $client->requestAsync('GET', 'https://example.test/alpha'),
            $client->requestAsync('GET', 'https://example.test/beta'),
        ];

        Utils::settle($promises)->wait();

        $creating = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(2, $creating);

        foreach ($creating as $trace) {
            $updating = $this->dispatcher->findUpdating(traceId: $trace->traceId);

            self::assertCount(1, $updating);
            self::assertNotContains(Processor::INTERRUPTED_TAG, $updating[0]->tags ?? []);

            $isAlpha = str_ends_with($trace->data['uri'], 'alpha');

            self::assertSame(
                [
                    'connect_time' => $isAlpha ? 0.1 : 0.2,
                    'primary_ip'   => $isAlpha ? '10.0.0.1' : '10.0.0.2',
                ],
                ($updating[0]->data ?? [])['transfer']
            );
        }
    }

    /**
     * What CurlFactory::finish() does: the stats first, then the outcome.
     *
     * @param array<string, mixed> $handlerStats
     */
    private static function curlLikeHandler(array $handlerStats, ResponseInterface|Throwable $outcome): callable
    {
        return static function (RequestInterface $request, array $options) use ($handlerStats, $outcome): PromiseInterface {
            if (isset($options['on_stats'])) {
                ($options['on_stats'])(
                    new TransferStats(
                        request: $request,
                        response: $outcome instanceof ResponseInterface ? $outcome : null,
                        transferTime: null,
                        handlerErrorData: $outcome instanceof Throwable ? $outcome : null,
                        handlerStats: $handlerStats
                    )
                );
            }

            return $outcome instanceof Throwable
                ? Create::rejectionFor($outcome)
                : Create::promiseFor($outcome);
        };
    }

    private function prepareHandler(callable $handler): HandlerStack
    {
        return app(GuzzleHandlerFactory::class)->prepareHandler(
            formatters: new RequestDataFormatters(),
            handlerStack: HandlerStack::create($handler)
        );
    }

    private function makeClient(callable $handler): Client
    {
        return new Client([
            'handler'     => $this->prepareHandler($handler),
            'http_errors' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function findStoppedData(TraceStatusEnum $status = TraceStatusEnum::Success): array
    {
        $creating = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);
        self::assertSame($status->value, $updating[0]->status);

        return $updating[0]->data ?? [];
    }
}
