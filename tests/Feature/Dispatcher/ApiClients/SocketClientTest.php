<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\ApiClients;

use Illuminate\Support\Carbon;
use JsonException;
use RuntimeException;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\Connection;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\SocketClient;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class SocketClientTest extends BaseTestCase
{
    /**
     * @throws JsonException
     */
    public function testSendTracesConnectsAndSendsPayload(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('isConnected')
            ->willReturn(false);

        $connection->expects(self::once())
            ->method('connect')
            ->with('token-1');

        $connection->expects(self::once())
            ->method('write')
            ->with(
                self::callback(
                    function (string $payloadJson) {
                        $payload = json_decode($payloadJson, true);

                        self::assertArrayHasKey('c', $payload);
                        self::assertArrayHasKey('u', $payload);

                        $creating = json_decode($payload['c'], true);
                        $updating = json_decode($payload['u'], true);

                        self::assertSame('trace-1', $creating[0]['tid']);
                        self::assertSame('trace-1', $updating[0]['tid']);
                        self::assertSame('2024-01-01T00:00:00.123Z', $creating[0]['lat']);
                        self::assertSame('2024-01-01T00:00:01.654Z', $updating[0]['plat']);

                        return true;
                    }
                )
            );

        $connection->expects(self::once())
            ->method('read')
            ->willReturn('received');

        $client = new SocketClient('token-1', $connection);

        $client->sendTraces($this->makeTraces());
    }

    /**
     * @throws JsonException
     */
    public function testSendTracesReconnectsOnWriteFailure(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('isConnected')
            ->willReturn(true);

        $connection->expects(self::once())
            ->method('connect')
            ->with('token-1');

        $connection->expects(self::exactly(2))
            ->method('write')
            ->willReturnCallback(function (string $payloadJson) {
                static $calls = 0;
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('fail');
                }

                return null;
            });

        $connection->expects(self::once())
            ->method('read')
            ->willReturn('received');

        $client = new SocketClient('token-1', $connection);

        $client->sendTraces($this->makeTraces());
    }

    /**
     * @throws JsonException
     */
    public function testSendTracesThrowsOnUnexpectedResponse(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('isConnected')->willReturn(true);
        $connection->method('write');
        $connection->method('read')->willReturn('nope');

        $client = new SocketClient('token-1', $connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected response from socket server');

        $client->sendTraces($this->makeTraces());
    }

    private function makeTraces(): TracesObject
    {
        /**
         * for laravel 10 support
         *
         * @var Carbon $loggedAt
         */
        $loggedAt = Carbon::create(2024, 1, 1, 3, 0, 0, 'Europe/Moscow');

        /**
         * for laravel 10 support
         *
         * @var Carbon $parentLoggedAt
         */
        $parentLoggedAt = Carbon::create(2024, 1, 1, 3, 0, 1, 'Europe/Moscow');

        return (new TracesObject())
            ->addCreating(
                new TraceCreateObject(
                    traceId: 'trace-1',
                    parentTraceId: null,
                    type: 'request',
                    status: 'started',
                    tags: ['tag'],
                    data: ['foo' => 'bar'],
                    duration: 1.25,
                    memory: 12.0,
                    cpu: 2.0,
                    isParent: true,
                    loggedAt: $loggedAt->setMicroseconds(123000)
                )
            )
            ->addUpdating(
                new TraceUpdateObject(
                    traceId: 'trace-1',
                    status: 'success',
                    profiling: null,
                    tags: ['t2'],
                    data: ['updated' => true],
                    duration: 0.25,
                    memory: 1.5,
                    cpu: 0.5,
                    parentLoggedAt: $parentLoggedAt->setMicroseconds(654000)
                )
            );
    }
}
