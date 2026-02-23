<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\ApiClients;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use SLoggerLaravel\Dispatcher\ApiClients\Http\HttpClient;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Profiling\Dto\ProfilingDataObject;
use SLoggerLaravel\Profiling\Dto\ProfilingObject;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class HttpClientTest extends BaseTestCase
{
    /**
     * @throws GuzzleException
     */
    public function testSendTracesBuildsCreateAndUpdatePayloads(): void
    {
        $client = $this->createMock(ClientInterface::class);

        $client->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(
                function (string $method, string $uri, array $options) {
                    static $callCount = 0;
                    $callCount++;

                    if ($callCount === 1) {
                        self::assertSame('post', $method);
                        self::assertSame('/traces-api', $uri);

                        $traces = $options['json']['traces'] ?? [];
                        self::assertCount(1, $traces);
                        self::assertSame('trace-1', $traces[0]['trace_id']);
                        self::assertSame('request', $traces[0]['type']);
                        self::assertSame('started', $traces[0]['status']);
                        self::assertSame('parent-1', $traces[0]['parent_trace_id']);
                        self::assertSame(['tag'], $traces[0]['tags']);
                        self::assertSame(1.25, $traces[0]['duration']);
                        self::assertSame(12.0, $traces[0]['memory']);
                        self::assertSame(2.0, $traces[0]['cpu']);
                        self::assertTrue($traces[0]['is_parent']);
                        self::assertIsString($traces[0]['data']);
                        self::assertSame(['foo' => 'bar'], json_decode($traces[0]['data'], true));
                    } elseif ($callCount === 2) {
                        self::assertSame('patch', $method);
                        self::assertSame('/traces-api', $uri);

                        $traces = $options['json']['traces'] ?? [];
                        self::assertCount(1, $traces);
                        self::assertSame('trace-1', $traces[0]['trace_id']);
                        self::assertSame('success', $traces[0]['status']);
                        self::assertSame(['t2'], $traces[0]['tags']);
                        self::assertSame(['updated' => true], json_decode($traces[0]['data'], true));
                        self::assertSame(0.25, $traces[0]['duration']);
                        self::assertSame(1.5, $traces[0]['memory']);
                        self::assertSame(0.5, $traces[0]['cpu']);
                        self::assertArrayHasKey('profiling', $traces[0]);
                    }

                    return $this->createMock(ResponseInterface::class);
                }
            );

        $httpClient = new HttpClient($client);

        $httpClient->sendTraces($this->makeTraces());
    }

    private function makeTraces(): TracesObject
    {
        /** @var Carbon $loggedAt */
        $loggedAt = Carbon::create(2024, 1, 1, 0, 0, 0, 'UTC');

        /** @var Carbon $parentLoggedAt */
        $parentLoggedAt = Carbon::create(2024, 1, 1, 0, 0, 1, 'UTC');

        $loggedAt       = $loggedAt->setMicroseconds(123456);
        $parentLoggedAt = $parentLoggedAt->setMicroseconds(654321);

        $profiling = new ProfilingObjects('main');
        $profiling->add(
            new ProfilingObject(
                raw: 'raw',
                calling: 'calling',
                callable: 'callable',
                data: new ProfilingDataObject(1, 2.0, 3.0, 4.0, 5.0)
            )
        );

        return (new TracesObject())
            ->addCreating(
                new TraceCreateObject(
                    traceId: 'trace-1',
                    parentTraceId: 'parent-1',
                    type: 'request',
                    status: 'started',
                    tags: ['tag'],
                    data: ['foo' => 'bar'],
                    duration: 1.25,
                    memory: 12.0,
                    cpu: 2.0,
                    isParent: true,
                    loggedAt: $loggedAt
                )
            )
            ->addUpdating(
                new TraceUpdateObject(
                    traceId: 'trace-1',
                    status: 'success',
                    profiling: $profiling,
                    tags: ['t2'],
                    data: ['updated' => true],
                    duration: 0.25,
                    memory: 1.5,
                    cpu: 0.5,
                    parentLoggedAt: $parentLoggedAt
                )
            );
    }
}
