<?php

namespace SLoggerLaravel\LocalProxy;

use SLoggerLaravel\Configs\LocalProxyConfig;
use SLoggerLaravel\LocalProxy\Objects\LocalProxyMetricsObject;
use Throwable;

class LocalProxyMetricsReader
{
    // state, scan, state again: a moved sender retries once, a busy one is
    // reported as unknown rather than computed from a mixed observation
    private const PENDING_READ_ATTEMPTS = 2;

    public function __construct(
        private readonly LocalProxyConfig $config,
        private readonly LocalProxySenderStateReader $stateReader,
    ) {
    }

    /**
     * The proxy replaces the file atomically, so a snapshot is never read
     * half-written; a missing or unreadable one is reported as null and left
     * for the caller to present.
     */
    public function read(): ?LocalProxyMetricsObject
    {
        $path = $this->getMetricsFilePath();

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return null;
            }

            /** @var array<string, mixed> $data */
            $metrics = LocalProxyMetricsObject::fromArray($data);
        } catch (Throwable) {
            return null;
        }

        return $metrics->withPendingBytes($this->computePendingBytes());
    }

    public function getMetricsFilePath(): string
    {
        return base_path(
            $this->config->getTracesPath() . '/' . LocalProxyConfig::METRICS_FILENAME
        );
    }

    /**
     * Unsent bytes still on disk: all other hourly files, plus the unsent
     * tail of the file at the saved sender position. The sender
     * position is read from the state file itself, not from the snapshot -
     * the snapshot can be a second old by the time the offset matters.
     *
     * The state file's identity (dev + inode) is compared before and after
     * the scan, with the first descriptor held open until then: the proxy
     * saves state by atomically renaming a fresh file over the old one, and
     * the pinned inode cannot be freed and reused by two saves in between
     * (an empty -> completed -> empty sequence reads as the same content AND
     * can recycle the same inode on ext filesystems). A moved state retries
     * once and is then reported as unknown (null).
     *
     * With a stable identity the offset is durable for the scan window and
     * appends landing mid-scan can only under-report. A file deleted mid-scan
     * without a state change - an expired file removed by retention - can
     * still inflate a single reading; the next poll self-corrects. All of
     * this holds while only the proxy writes the directory.
     *
     * Null when the backlog cannot be known: the directory does not scan or
     * the state file is unreadable/invalid.
     */
    private function computePendingBytes(): ?int
    {
        $directory = base_path($this->config->getTracesPath());

        for ($attempt = 0; $attempt < self::PENDING_READ_ATTEMPTS; $attempt++) {
            $before = $this->stateReader->read($directory);

            if ($before === null) {
                return null;
            }

            try {
                $sizes = $this->scanHourlyFileSizes($directory);

                if ($sizes === null) {
                    return null;
                }

                $after = $this->stateReader->read($directory);

                if ($after === null) {
                    return null;
                }

                $consistent = $before->identity === $after->identity;
                $state      = $after->state;

                $after->close();

                if (!$consistent) {
                    continue;
                }

                $pendingBytes = 0;

                foreach ($sizes as $name => $size) {
                    if ($state->file !== null && $name === $state->file) {
                        // the file the sender is positioned in: only its tail
                        // past the durable offset is pending
                        if (!$state->completed) {
                            $pendingBytes += max(0, $size - $state->offset);
                        }

                        continue;
                    }

                    // Older proxy versions can leave an earlier file after a
                    // clock rollback. Only the named file has a saved offset.
                    $pendingBytes += $size;
                }

                return $pendingBytes;
            } finally {
                $before->close();
            }
        }

        return null;
    }

    /**
     * @return array<string, int>|null  hourly file name => size, null when the
     *                                  directory does not scan
     */
    private function scanHourlyFileSizes(string $directory): ?array
    {
        $names = @scandir($directory);

        if ($names === false) {
            return null;
        }

        $sizes = [];

        foreach ($names as $name) {
            // hourly trace files only - the metrics file, state, lock and logs
            // in the same directory do not match
            if (!LocalProxyConfig::isValidHourlyFileName($name)) {
                continue;
            }

            $path = $directory . '/' . $name;

            // a poll is a repeated observation: the size must be fresh
            clearstatcache(true, $path);

            // mirrors Go's DirEntry.Type().IsRegular(): the exact S_IFMT mask
            // (a socket with 0140000 passes a & 0120000 check), a symlink is
            // not followed, and anything but a plain file is skipped
            $stat = @lstat($path);

            // the file may vanish between the scan and the stat: a sealed
            // file fully sent is deleted by the proxy itself
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                continue;
            }

            $sizes[$name] = $stat['size'];
        }

        return $sizes;
    }
}
