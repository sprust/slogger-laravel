<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\LocalProxy\Metrics;

use Illuminate\Support\Carbon;
use SLoggerLaravel\LocalProxy\Objects\LocalProxyMetricsObject;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

abstract class MetricsTestCase extends BaseTestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/slogger-metrics-test-' . bin2hex(random_bytes(6));

        mkdir($this->root . '/storage/slogger/traces', 0700, true);

        $this->getApp()->setBasePath($this->root);

        config()->set('slogger.local_proxy.traces_path', 'storage/slogger/traces');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function metrics(array $overrides = [], ?int $pendingBytes = 1): LocalProxyMetricsObject
    {
        return LocalProxyMetricsObject::fromArray($this->makeSnapshot($overrides))
            ->withPendingBytes($pendingBytes);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function writeSnapshot(array $overrides = []): void
    {
        file_put_contents(
            $this->metricsFilePath(),
            (string) json_encode($this->makeSnapshot($overrides))
        );
    }

    protected function writeHourlyFile(string $name, int $size): void
    {
        file_put_contents(
            $this->tracesPath() . '/' . $name,
            str_repeat('x', $size)
        );
    }

    protected function writeStateFile(?string $file, int $offset, bool $completed): void
    {
        // atomic like the proxy's own save: a fresh file renamed over the old
        // one, so every save is a new inode
        $json = (string) json_encode([
            'version'   => 1,
            'file'      => $file ?? '',
            'offset'    => $offset,
            'completed' => $completed,
        ]);

        $temporary = tempnam($this->tracesPath(), '.state-test-');

        self::assertNotFalse($temporary);

        file_put_contents($temporary, $json);
        rename($temporary, $this->tracesPath() . '/local-proxy-state.json');
    }

    protected function tracesPath(): string
    {
        return $this->root . '/storage/slogger/traces';
    }

    protected function metricsFilePath(): string
    {
        return $this->tracesPath() . '/local-proxy-metrics.json';
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function makeSnapshot(array $overrides = []): array
    {
        return array_replace_recursive(
            [
                'collected_at'   => Carbon::now()->toIso8601String(),
                'started_at'     => '2026-11-19T09:00:00+00:00',
                'uptime_seconds' => 3600.5,
                'process'        => [
                    'goroutines'        => 21,
                    'heap_alloc_bytes'  => 1000000,
                    'heap_sys_bytes'    => 2000000,
                    'stack_inuse_bytes' => 300000,
                    'rss_bytes'         => 5000000,
                ],
                'server'         => [
                    'connections'              => 2,
                    'idle_connections'         => 1,
                    'connection_slots_used'    => 2,
                    'connection_limit'         => 32,
                    'queued_records'           => 0,
                    'queue_capacity_records'   => 1024,
                    'input_budget_used_bytes'  => 0,
                    'input_budget_total_bytes' => 268435456,
                ],
                'storage'        => [
                    'hourly_files'           => 2,
                    'hourly_bytes'           => 12345,
                    'spool_limit_bytes'      => 1073741824,
                    'error_log_files'        => 1,
                    'appended_records_total' => 100,
                    'appended_bytes_total'   => 9999,
                    'oldest_hourly_file'     => '2026_11_19_09',
                    'newest_hourly_file'     => '2026_11_19_10',
                    'state'                  => [
                        'file'      => '2026_11_19_09',
                        'offset'    => 5000,
                        'completed' => false,
                    ],
                ],
                'sender'         => [
                    'sent_batches_total'         => 50,
                    'sent_operations_total'      => 98,
                    'sent_bytes_total'           => 9000,
                    'failed_send_attempts_total' => 2,
                ],
            ],
            $overrides
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        foreach ($entries === false ? [] : $entries as $entry) {
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
