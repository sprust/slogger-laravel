<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\ApiClients;

use Illuminate\Support\Carbon;
use JsonException;
use Psr\Log\NullLogger;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\Connection;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\SocketClient;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

/**
 * End-to-end over a real socket: the receiver goes away between two sends
 * (restart, deploy, network fault) and the client has to notice it.
 */
class SocketReconnectTest extends BaseTestCase
{
    /**
     * @throws JsonException
     */
    public function testSendTracesSurvivesReceiverRestart(): void
    {
        [$process, $address] = $this->startReceiver(connections: 2);

        try {
            $client = new SocketClient(
                apiToken: 'token-1',
                connection: new Connection($address, new NullLogger(), timeoutSeconds: 5)
            );

            $client->sendTraces($this->makeTraces('trace-1'));

            // the receiver has closed the connection by now
            usleep(200000);

            $startedAt = microtime(true);

            // must reconnect transparently instead of failing
            // with "Failed to read from socket by timeout"
            $client->sendTraces($this->makeTraces('trace-2'));

            self::assertLessThan(5, microtime(true) - $startedAt);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    /**
     * @return array{0: resource, 1: string}
     */
    private function startReceiver(int $connections): array
    {
        // tcp, like production: on a closed tcp connection the first write still
        // reports success — which is exactly the case being tested
        $address = 'tcp://127.0.0.1:0';

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $code = sprintf(
            'require "vendor/autoload.php"; %s::run("%s", %d);',
            FakeSocketReceiver::class,
            $address,
            $connections
        );

        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            $descriptors,
            $pipes,
            dirname(__DIR__, 4)
        );

        self::assertNotFalse($process, 'failed to start the fake receiver');

        // the receiver keeps stderr open for its whole life:
        // reading it must never block the test
        stream_set_blocking($pipes[2], false);

        $boundAddress = fgets($pipes[1], 1024);

        if (!is_string($boundAddress)) {
            self::fail(
                'the fake receiver did not start: ' . (string) stream_get_contents($pipes[2])
            );
        }

        return [$process, 'tcp://' . trim($boundAddress)];
    }

    private function makeTraces(string $traceId): TracesObject
    {
        /**
         * for laravel 10 support
         *
         * @var Carbon $loggedAt
         */
        $loggedAt = Carbon::create(2024, 1, 1, 3, 0, 0, 'Europe/Moscow');

        return (new TracesObject())->addCreating(
            new TraceCreateObject(
                traceId: $traceId,
                parentTraceId: null,
                type: 'request',
                status: 'success',
                tags: [],
                data: ['foo' => 'bar'],
                duration: 1.0,
                memory: 1.0,
                cpu: 1.0,
                isParent: true,
                loggedAt: $loggedAt
            )
        );
    }
}
