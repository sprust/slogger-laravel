<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\LocalProxy;

use Illuminate\Support\Carbon;
use DateTimeImmutable;
use JsonException;
use RuntimeException;
use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\ApiClients\ApiClientFactory;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class LocalProxyIntegrationTest extends BaseTestCase
{
    private const TOKEN = 'integration-token';

    private string $root;
    private string $capturePath;
    private string $binary;

    /** @var list<array{process: resource, pipes: array<int, resource>}> */
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $binary = getenv('SLOGGER_LOCAL_PROXY_TEST_BINARY');

        if (!is_string($binary) || $binary === '' || !is_file($binary)) {
            $this->markTestSkipped('SLOGGER_LOCAL_PROXY_TEST_BINARY is not provided.');
        }

        $this->binary      = $binary;
        $this->root        = sys_get_temp_dir() . '/slogger-local-proxy-integration-' . bin2hex(random_bytes(6));
        $this->capturePath = $this->root . '/upstream.jsonl';
        mkdir($this->root . '/storage/slogger/traces', 0700, true);
        touch($this->capturePath);

        $this->getApp()->setBasePath($this->root);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $entry) {
            $status = proc_get_status($entry['process']);

            if ($status['running']) {
                proc_terminate($entry['process']);
            }

            foreach ($entry['pipes'] as $pipe) {
                fclose($pipe);
            }

            proc_close($entry['process']);
        }

        $this->removeDirectory($this->root ?? '');

        parent::tearDown();
    }

    /**
     * @throws JsonException
     */
    public function testRealPhpClientProxyRestartUpstreamRetryAndSingleInstance(): void
    {
        [$upstream, $address] = $this->startUpstream('tcp://127.0.0.1:0');
        $this->writeEnv($address);
        $proxy = $this->startProxy();

        try {
            $this->client()->sendTraces($this->makeTraces('trace-1'));
            $this->client()->sendTraces($this->makeTraces('trace-2'));
            $this->waitForTraces(['trace-1', 'trace-2']);

            $statePath = $this->root . '/storage/slogger/traces/local-proxy-state.json';
            $this->waitFor(function () use ($statePath): bool {
                $state = is_file($statePath)
                    ? json_decode((string) file_get_contents($statePath), true)
                    : null;

                return ($state['file'] ?? null) === ''
                    && ($state['offset'] ?? null) === 0
                    && ($state['completed'] ?? null) === false;
            });

            $state = json_decode((string) file_get_contents($statePath), true);
            self::assertSame('', $state['file']);
            self::assertSame(0, $state['offset']);
            self::assertFalse($state['completed']);

            $metrics = $this->waitForMetrics(
                fn(array $metrics): bool => ($metrics['storage']['appended_records_total'] ?? 0) === 2
                    && ($metrics['sender']['sent_operations_total'] ?? 0) === 2
            );
            self::assertSame(0, $metrics['sender']['failed_send_attempts_total']);

            $this->stopProxy($proxy);
            $proxy = $this->startProxy();

            $second = proc_open(
                [$this->binary, '-env', $this->root . '/.env'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $secondPipes,
                $this->root
            );
            self::assertNotFalse($second);
            $secondOutput = $this->waitForExit($second, $secondPipes);

            foreach ($secondPipes as $pipe) {
                fclose($pipe);
            }

            proc_close($second);

            self::assertStringContainsString('already running', $secondOutput);

            $this->stopUpstream($upstream);
            $this->client()->sendTraces($this->makeTraces('trace-3'));
            $this->waitFor(function (): bool {
                $path = $this->root . '/storage/slogger/traces/local-proxy-errors-' . date('Y_m_d') . '.logs';

                return is_file($path)
                    && str_contains((string) file_get_contents($path), 'failed to send traces to SLogger');
            });

            $metrics = $this->waitForMetrics(
                fn(array $metrics): bool => ($metrics['storage']['appended_records_total'] ?? 0) === 1
                    && ($metrics['sender']['sent_operations_total'] ?? 0) === 0
                    && ($metrics['sender']['failed_send_attempts_total'] ?? 0) >= 1
            );

            proc_terminate($proxy['process'], 9);
            $this->waitForExit($proxy['process'], $proxy['pipes']);
            $upstream = $this->startUpstream($address)[0];
            $proxy    = $this->startProxy();

            $this->waitForTraces(['trace-1', 'trace-2', 'trace-3'], 20);

            $metrics = $this->waitForMetrics(
                fn(array $metrics): bool => ($metrics['storage']['appended_records_total'] ?? 0) === 0
                    && ($metrics['sender']['sent_operations_total'] ?? 0) === 1
            );
            self::assertSame(0, $metrics['sender']['failed_send_attempts_total']);
            $lastLiveCollectedAt = new DateTimeImmutable($metrics['collected_at']);

            $this->stopProxy($proxy);
            $finalMetrics = $this->readMetrics();
            self::assertSame(0, $finalMetrics['storage']['appended_records_total']);
            self::assertSame(1, $finalMetrics['sender']['sent_operations_total']);
            self::assertSame(0, $finalMetrics['sender']['failed_send_attempts_total']);
            self::assertSame(0, $finalMetrics['server']['connections']);
            self::assertSame(0, $finalMetrics['server']['connection_slots_used']);
            self::assertGreaterThan(
                $lastLiveCollectedAt,
                new DateTimeImmutable($finalMetrics['collected_at'])
            );
        } finally {
            $status = proc_get_status($proxy['process']);

            if ($status['running']) {
                $this->stopProxy($proxy);
            }

            $upstreamStatus = proc_get_status($upstream['process']);

            if ($upstreamStatus['running']) {
                $this->stopUpstream($upstream);
            }
        }
    }

    private function client(): \SLoggerLaravel\Dispatcher\ApiClients\ApiClientInterface
    {
        config()->set('slogger.token', 'local-token-is-not-verified');
        config()->set('slogger.dispatchers.queue.api_clients.default', 'socket');
        config()->set('slogger.dispatchers.queue.api_clients.socket.type', 'proxy');
        config()->set('slogger.local_proxy.traces_path', '/storage/slogger/traces/');

        return (new ApiClientFactory(new GeneralConfig(), new DispatcherQueueConfig()))->create('socket');
    }

    private function writeEnv(string $upstreamAddress): void
    {
        $contents = "SLOGGER_TOKEN=" . self::TOKEN . "\n"
            . "SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL=$upstreamAddress\n"
            . "SLOGGER_LOCAL_PROXY_TRACES_PATH=/storage/slogger/traces/\n"
            . "SLOGGER_LOCAL_PROXY_RETENTION_HOURS=24\n";

        file_put_contents($this->root . '/.env', $contents);
    }

    /** @return array{process: resource, pipes: array<int, resource>} */
    private function startProxy(): array
    {
        $process = proc_open(
            [$this->binary, '-env', $this->root . '/.env'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root
        );
        self::assertNotFalse($process);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $entry             = ['process' => $process, 'pipes' => $pipes];
        $this->processes[] = $entry;

        $this->waitFor(function (): bool {
            $socket = $this->root . '/storage/slogger/traces/local-proxy.sock';

            if (!file_exists($socket)) {
                return false;
            }

            $connection = @stream_socket_client('unix://' . $socket, $errno, $error, 0.1);

            if ($connection === false) {
                return false;
            }

            fclose($connection);

            return true;
        });

        return $entry;
    }

    /** @param array{process: resource, pipes: array<int, resource>} $entry */
    private function stopProxy(array $entry): void
    {
        proc_terminate($entry['process'], 15);
        $output = $this->waitForExit($entry['process'], $entry['pipes']);

        self::assertStringNotContainsString('timed out', $output);
    }

    /** @return array{0: array{process: resource, pipes: array<int, resource>}, 1: string} */
    private function startUpstream(string $address): array
    {
        $code = sprintf(
            'require %s; %s::run(%s, %s, %s);',
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
            FakeUpstream::class,
            var_export($address, true),
            var_export(self::TOKEN, true),
            var_export($this->capturePath, true)
        );
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3)
        );
        self::assertNotFalse($process);

        $bound = fgets($pipes[1]);

        if (!is_string($bound)) {
            self::fail('Fake upstream failed: ' . stream_get_contents($pipes[2]));
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $entry             = ['process' => $process, 'pipes' => $pipes];
        $this->processes[] = $entry;

        return [$entry, 'tcp://' . trim($bound)];
    }

    /** @param array{process: resource, pipes: array<int, resource>} $entry */
    private function stopUpstream(array $entry): void
    {
        proc_terminate($entry['process']);

        $this->waitForExit($entry['process'], $entry['pipes']);
    }

    /**
     * @param resource             $process
     * @param array<int, resource> $pipes
     */
    private function waitForExit(mixed $process, array $pipes): string
    {
        $deadline = microtime(true) + 6;

        do {
            $status = proc_get_status($process);

            if (!$status['running']) {
                $output = '';

                foreach ($pipes as $pipe) {
                    $output .= (string) stream_get_contents($pipe);
                }

                return $output;
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        proc_terminate($process, 9);

        throw new RuntimeException('Process did not exit before timeout.');
    }

    /**
     * @param callable(array<string, mixed>): bool $condition
     *
     * @return array<string, mixed>
     */
    private function waitForMetrics(callable $condition): array
    {
        $metrics = [];

        $this->waitFor(function () use (&$metrics, $condition): bool {
            $path = $this->root . '/storage/slogger/traces/local-proxy-metrics.json';

            if (!is_file($path)) {
                return false;
            }

            $metrics = json_decode((string) file_get_contents($path), true);

            return $condition($metrics);
        });

        return $metrics;
    }

    /** @return array<string, mixed> */
    private function readMetrics(): array
    {
        $path = $this->root . '/storage/slogger/traces/local-proxy-metrics.json';

        return json_decode((string) file_get_contents($path), true);
    }

    /** @param list<string> $traceIds */
    private function waitForTraces(array $traceIds, int $timeout = 10): void
    {
        $this->waitFor(function () use ($traceIds): bool {
            $received = $this->receivedTraceIds();

            return array_diff($traceIds, $received) === [];
        }, $timeout);
    }

    /** @return list<string> */
    private function receivedTraceIds(): array
    {
        $ids = [];

        foreach (file($this->capturePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $payload = json_decode($line, true);

            foreach (['c', 'u'] as $key) {
                foreach (json_decode($payload[$key] ?? '[]', true) as $trace) {
                    $ids[] = $trace['tid'];
                }
            }
        }

        return $ids;
    }

    private function waitFor(callable $condition, int $timeout = 10): void
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(20000);
        }

        self::fail('Integration condition was not met before timeout.');
    }

    private function makeTraces(string $traceId): TracesObject
    {
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
                pid: null,
                loggedAt: Carbon::now()
            )
        );
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        $entries = scandir($directory) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
