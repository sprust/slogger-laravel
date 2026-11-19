<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\LocalProxy\Metrics;

use Illuminate\Support\Carbon;
use SLoggerLaravel\LocalProxy\LocalProxyMetricsReader;
use SLoggerLaravel\LocalProxy\LocalProxySenderStateRead;
use SLoggerLaravel\LocalProxy\LocalProxySenderStateReader;
use SLoggerLaravel\LocalProxy\Objects\LocalProxyStorageStateObject;
use SLoggerLaravel\Tests\Feature\LocalProxy\Metrics\Support\FakeSenderStateReader;
use SLoggerLaravel\Tests\Feature\LocalProxy\Metrics\Support\InterleavingSenderStateReader;

class LocalProxyMetricsReaderTest extends MetricsTestCase
{
    private LocalProxyMetricsReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = $this->getApp()->make(LocalProxyMetricsReader::class);
    }

    public function testReadsSnapshot(): void
    {
        $this->writeSnapshot();

        $metrics = $this->reader->read();

        self::assertNotNull($metrics);
        self::assertSame(21, $metrics->process->goroutines);
        self::assertSame(5000000, $metrics->process->rssBytes);
        self::assertSame(2, $metrics->server->connections);
        self::assertSame(100, $metrics->storage->appendedRecordsTotal);
        self::assertSame('2026_11_19_09', $metrics->storage->state->file);
        self::assertSame(5000, $metrics->storage->state->offset);
        self::assertFalse($metrics->storage->state->completed);
        self::assertSame(98, $metrics->sender->sentOperationsTotal);
        self::assertSame(3600.5, $metrics->uptimeSeconds);
        // no hourly files written: nothing pending
        self::assertSame(0, $metrics->pendingBytes);
    }

    public function testParsesNanosecondCollectedAt(): void
    {
        $this->writeSnapshot(['collected_at' => '2026-11-19T10:00:01.123456789+03:00']);

        $metrics = $this->reader->read();

        self::assertNotNull($metrics);
        self::assertSame('2026-11-19 10:00:01', $metrics->collectedAt->format('Y-m-d H:i:s'));
    }

    public function testReturnsNullWhenFileIsMissing(): void
    {
        self::assertNull($this->reader->read());
    }

    public function testReturnsNullOnInvalidJson(): void
    {
        file_put_contents($this->metricsFilePath(), '{invalid');

        self::assertNull($this->reader->read());
    }

    public function testReturnsNullWithoutCollectedAt(): void
    {
        $snapshot = $this->makeSnapshot();
        unset($snapshot['collected_at']);

        file_put_contents($this->metricsFilePath(), (string) json_encode($snapshot));

        self::assertNull($this->reader->read());
    }

    public function testReturnsNullOnRelativeCollectedAt(): void
    {
        $this->writeSnapshot(['collected_at' => 'now']);

        self::assertNull($this->reader->read());
    }

    public function testReturnsNullOnImpossibleCalendarDate(): void
    {
        // Carbon::parse would silently normalize February 30 into March 2
        $this->writeSnapshot(['collected_at' => '2026-02-30T00:00:00Z']);

        self::assertNull($this->reader->read());
    }

    public function testReturnsNullOnTimestampOnlyFile(): void
    {
        file_put_contents(
            $this->metricsFilePath(),
            (string) json_encode(['collected_at' => '2026-11-19T10:00:01+03:00'])
        );

        self::assertNull($this->reader->read());
    }

    public function testReturnsNullOnWrongFieldTypes(): void
    {
        $this->writeSnapshot(['process' => ['goroutines' => 'many']]);

        self::assertNull($this->reader->read());
    }

    public function testReturnsNullOnMissingRequiredField(): void
    {
        $snapshot = $this->makeSnapshot();
        unset($snapshot['sender']['sent_operations_total']);

        file_put_contents($this->metricsFilePath(), (string) json_encode($snapshot));

        self::assertNull($this->reader->read());
    }

    public function testReturnsNullOnWrongOmitemptyFieldTypes(): void
    {
        $this->writeSnapshot(['process' => ['rss_bytes' => 'many']]);

        self::assertNull($this->reader->read());
    }

    public function testReturnsNullOnWrongStateFileType(): void
    {
        $this->writeSnapshot(['storage' => ['state' => ['file' => 123]]]);

        self::assertNull($this->reader->read());
    }

    public function testReturnsNullOnInfiniteUptime(): void
    {
        // json_decode turns 1e309 into INF, and json_encode cannot write INF
        // back: the line format would read fine and the json one would fail
        $json = (string) json_encode($this->makeSnapshot());

        file_put_contents(
            $this->metricsFilePath(),
            str_replace('"uptime_seconds":3600.5', '"uptime_seconds":1e309', $json)
        );

        self::assertNull($this->reader->read());
    }

    public function testReturnsNullOnNegativeUptime(): void
    {
        $this->writeSnapshot(['uptime_seconds' => -1]);

        self::assertNull($this->reader->read());
    }

    public function testHandlesAbsentOmitemptyFields(): void
    {
        $snapshot = $this->makeSnapshot();
        unset(
            $snapshot['process']['rss_bytes'],
            $snapshot['storage']['oldest_hourly_file'],
            $snapshot['storage']['newest_hourly_file'],
            $snapshot['storage']['state']['file'],
        );

        file_put_contents($this->metricsFilePath(), (string) json_encode($snapshot));

        $metrics = $this->reader->read();

        self::assertNotNull($metrics);
        self::assertNull($metrics->process->rssBytes);
        self::assertNull($metrics->storage->oldestHourlyFile);
        self::assertNull($metrics->storage->newestHourlyFile);
        self::assertNull($metrics->storage->state->file);
    }

    public function testIsStale(): void
    {
        $metrics = $this->metrics(['collected_at' => '2026-11-19T10:00:00+00:00']);

        Carbon::setTestNow('2026-11-19T10:00:10+00:00');
        self::assertFalse($metrics->isStale(10));

        Carbon::setTestNow('2026-11-19T10:00:11+00:00');
        self::assertTrue($metrics->isStale(10));
    }

    public function testIsStaleForMateriallyFutureSnapshot(): void
    {
        $metrics = $this->metrics(['collected_at' => '2026-11-19T10:00:00+00:00']);

        // a few seconds of clock skew is fine
        Carbon::setTestNow('2026-11-19T09:59:55+00:00');
        self::assertFalse($metrics->isStale(10));

        // a snapshot materially from the future is as unusable as an old one
        Carbon::setTestNow('2026-11-19T09:59:49+00:00');
        self::assertTrue($metrics->isStale(10));
    }

    public function testComputesPendingBytesFromDurableState(): void
    {
        $this->writeHourlyFile('2026_11_19_09', 6000);
        $this->writeHourlyFile('2026_11_19_10', 500);
        $this->writeStateFile('2026_11_19_09', 5000, false);
        $this->writeSnapshot();

        $metrics = $this->reader->read();

        // state: file 2026_11_19_09, offset 5000, not completed
        // -> 1000 bytes of its tail plus the whole newer file
        self::assertNotNull($metrics);
        self::assertSame(1500, $metrics->pendingBytes);
    }

    public function testPendingBytesReadsStateFromDiskNotFromSnapshot(): void
    {
        $this->writeHourlyFile('2026_11_19_09', 6000);
        $this->writeHourlyFile('2026_11_19_10', 500);

        // the snapshot is a second old: it still shows the file unsent...
        $this->writeSnapshot([
            'storage' => ['state' => ['file' => '2026_11_19_09', 'offset' => 0, 'completed' => false]],
        ]);

        // ...but the durable state says it has been sent since
        $this->writeStateFile('2026_11_19_09', 6000, true);

        $metrics = $this->reader->read();

        self::assertNotNull($metrics);
        self::assertSame(500, $metrics->pendingBytes);
    }

    public function testPendingBytesWithCompletedStateFile(): void
    {
        $this->writeHourlyFile('2026_11_19_09', 6000);
        $this->writeHourlyFile('2026_11_19_10', 500);
        $this->writeStateFile('2026_11_19_09', 6000, true);
        $this->writeSnapshot();

        $metrics = $this->reader->read();

        self::assertNotNull($metrics);
        self::assertSame(500, $metrics->pendingBytes);
    }

    public function testPendingBytesWithoutAnySendState(): void
    {
        $this->writeHourlyFile('2026_11_19_09', 6000);
        $this->writeHourlyFile('2026_11_19_10', 500);

        // no state file: nothing ever sent, everything is pending (Go's Load
        // reads a missing state as an empty one)
        $this->writeSnapshot();

        $metrics = $this->reader->read();

        self::assertNotNull($metrics);
        self::assertSame(6500, $metrics->pendingBytes);
    }

    public function testPendingBytesNeverNegativeWhenOffsetOutgrowsFile(): void
    {
        // the file was rotated or truncated between the offset save and now
        $this->writeHourlyFile('2026_11_19_09', 100);
        $this->writeStateFile('2026_11_19_09', 5000, false);
        $this->writeSnapshot();

        $metrics = $this->reader->read();

        self::assertNotNull($metrics);
        self::assertSame(0, $metrics->pendingBytes);
    }

    public function testPendingBytesSkipsVanishedStateFile(): void
    {
        // state points at a file the proxy has already deleted after sending
        $this->writeHourlyFile('2026_11_19_10', 500);
        $this->writeStateFile('2026_11_19_09', 6000, true);
        $this->writeSnapshot();

        $metrics = $this->reader->read();

        self::assertNotNull($metrics);
        self::assertSame(500, $metrics->pendingBytes);
    }

    public function testPendingBytesIgnoresDirectoriesAndSymlinks(): void
    {
        $this->writeHourlyFile('2026_11_19_10', 1500);

        // a directory and a symlink carrying hourly file names: Go's
        // DirEntry.Type().IsRegular() counts neither, and neither do we
        mkdir($this->tracesPath() . '/2026_11_19_08');
        symlink(
            $this->tracesPath() . '/2026_11_19_10',
            $this->tracesPath() . '/2026_11_19_07'
        );

        $this->writeSnapshot();

        $metrics = $this->reader->read();

        self::assertNotNull($metrics);
        self::assertSame(1500, $metrics->pendingBytes);
    }

    public function testPendingBytesNullOnInvalidStateFile(): void
    {
        $this->writeHourlyFile('2026_11_19_10', 500);
        file_put_contents(
            $this->tracesPath() . '/local-proxy-state.json',
            (string) json_encode(['version' => 2, 'file' => '', 'offset' => 0, 'completed' => false])
        );
        $this->writeSnapshot();

        $metrics = $this->reader->read();

        // the snapshot itself is fine; only the backlog is unknown
        self::assertNotNull($metrics);
        self::assertNull($metrics->pendingBytes);
    }

    public function testPendingBytesNullWhenStatePathIsNotRegularFile(): void
    {
        $this->writeHourlyFile('2026_11_19_10', 500);

        // a directory with the state file's name: Go reads it as EISDIR, so
        // this is not a missing state - the backlog is unknown
        mkdir($this->tracesPath() . '/local-proxy-state.json');

        $this->writeSnapshot();

        $metrics = $this->reader->read();

        self::assertNotNull($metrics);
        self::assertNull($metrics->pendingBytes);
    }

    public function testPendingBytesNullWhenStateChangesMidScan(): void
    {
        $this->writeHourlyFile('2026_11_19_09', 6000);
        $this->writeSnapshot();

        // every save is a new inode: the state identity keeps changing between
        // the pre-scan and post-scan reads, so the bounded retry is exhausted
        // and the backlog is reported as unknown rather than computed from a
        // mixed observation
        $this->getApp()->instance(
            LocalProxySenderStateReader::class,
            new FakeSenderStateReader([
                $this->stateRead(100, new LocalProxyStorageStateObject('2026_11_19_09', 0, false)),
                $this->stateRead(101, new LocalProxyStorageStateObject('2026_11_19_09', 6000, true)),
                $this->stateRead(102, new LocalProxyStorageStateObject('2026_11_19_09', 6000, true)),
                $this->stateRead(103, new LocalProxyStorageStateObject('2026_11_19_09', 6500, true)),
            ])
        );

        $metrics = $this->getApp()->make(LocalProxyMetricsReader::class)->read();

        self::assertNotNull($metrics);
        self::assertNull($metrics->pendingBytes);
    }

    public function testPendingBytesRetrySucceedsWhenStateSettlesMidScan(): void
    {
        $this->writeHourlyFile('2026_11_19_09', 6000);
        $this->writeSnapshot();

        // moved once, then settled at offset 5000: the first attempt mismatches,
        // the retry computes from the settled state
        $settled = $this->stateRead(101, new LocalProxyStorageStateObject('2026_11_19_09', 5000, false));

        $this->getApp()->instance(
            LocalProxySenderStateReader::class,
            new FakeSenderStateReader([
                $this->stateRead(100, new LocalProxyStorageStateObject('2026_11_19_09', 0, false)),
                $settled,
                $settled,
                $settled,
            ])
        );

        $metrics = $this->getApp()->make(LocalProxyMetricsReader::class)->read();

        self::assertNotNull($metrics);
        self::assertSame(1000, $metrics->pendingBytes);
    }

    public function testPendingBytesSurvivesStateRewriteAndFileDeletionMidScan(): void
    {
        $this->writeHourlyFile('2026_11_19_09', 6000);
        $this->writeStateFile(null, 0, false);
        $this->writeSnapshot();

        // between the scan and the second state read the proxy does its whole
        // cycle: saves the completed state, deletes the file, writes an empty
        // state. on ext filesystems the two renames can recycle the freed
        // inode, so only the descriptor held from the first read tells the
        // reads apart; either way the stale scan must not be reused
        $this->getApp()->instance(
            LocalProxySenderStateReader::class,
            new InterleavingSenderStateReader(
                new LocalProxySenderStateReader(),
                function (string $directory): void {
                    $this->writeStateFile('2026_11_19_09', 6000, true);
                    unlink($directory . '/2026_11_19_09');
                    $this->writeStateFile(null, 0, false);
                },
                beforeReadIndex: 1,
            )
        );

        $metrics = $this->getApp()->make(LocalProxyMetricsReader::class)->read();

        // a trusting comparison would report 6000 for the deleted file
        self::assertNotNull($metrics);
        self::assertSame(0, $metrics->pendingBytes);
    }

    public function testToFlatArrayFlattensWithDotKeys(): void
    {
        $flat = $this->metrics(pendingBytes: 1500)->toFlatArray();

        self::assertSame(21, $flat['process.goroutines']);
        self::assertSame(2, $flat['server.connections']);
        self::assertSame(5000, $flat['storage.state.offset']);
        self::assertFalse($flat['storage.state.completed']);
        self::assertSame(98, $flat['sender.sent_operations_total']);
        self::assertSame(1500, $flat['pending_bytes']);
    }

    private function stateRead(int $inode, LocalProxyStorageStateObject $state): LocalProxySenderStateRead
    {
        return new LocalProxySenderStateRead(
            identity: ['dev' => 1, 'ino' => $inode],
            state: $state,
        );
    }
}
