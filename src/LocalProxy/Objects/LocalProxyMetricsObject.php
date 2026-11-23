<?php

namespace SLoggerLaravel\LocalProxy\Objects;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class LocalProxyMetricsObject
{
    private const TIMESTAMP_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/';

    private const TIMESTAMP_OFFSET_PATTERN = '/([+-])(\d{2}):(\d{2})\z/';

    public function __construct(
        public readonly Carbon $collectedAt,
        public readonly Carbon $startedAt,
        public readonly float $uptimeSeconds,
        public readonly LocalProxyProcessMetricsObject $process,
        public readonly LocalProxyServerMetricsObject $server,
        public readonly LocalProxyStorageMetricsObject $storage,
        public readonly LocalProxySenderMetricsObject $sender,
        // durable backlog in bytes, computed by the reader from the sender
        // state file against the hourly files; null when the object was built
        // without it (direct fromArray calls) or the backlog is unknown
        public readonly ?int $pendingBytes = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        return new self(
            collectedAt: self::parseTimestamp($data, 'collected_at'),
            startedAt: self::parseTimestamp($data, 'started_at'),
            uptimeSeconds: LocalProxyMetricsSchema::nonNegativeFiniteFloat($data, 'uptime_seconds', ''),
            process: LocalProxyProcessMetricsObject::fromArray(
                LocalProxyMetricsSchema::section($data, 'process', '')
            ),
            server: LocalProxyServerMetricsObject::fromArray(
                LocalProxyMetricsSchema::section($data, 'server', '')
            ),
            storage: LocalProxyStorageMetricsObject::fromArray(
                LocalProxyMetricsSchema::section($data, 'storage', '')
            ),
            sender: LocalProxySenderMetricsObject::fromArray(
                LocalProxyMetricsSchema::section($data, 'sender', '')
            ),
        );
    }

    public function withPendingBytes(?int $pendingBytes): self
    {
        return new self(
            collectedAt: $this->collectedAt,
            startedAt: $this->startedAt,
            uptimeSeconds: $this->uptimeSeconds,
            process: $this->process,
            server: $this->server,
            storage: $this->storage,
            sender: $this->sender,
            pendingBytes: $pendingBytes,
        );
    }

    /**
     * The proxy publishes a snapshot every second; an old one means the proxy
     * is dead or has stopped writing. A snapshot materially from the future
     * (clock skew, a hand-written file) is just as unusable, so the age is
     * absolute.
     */
    public function isStale(int $maxAgeSeconds): bool
    {
        return abs(Carbon::now()->diffInSeconds($this->collectedAt)) > $maxAgeSeconds;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'collected_at'   => $this->collectedAt->toIso8601String(),
            'started_at'     => $this->startedAt->toIso8601String(),
            'uptime_seconds' => $this->uptimeSeconds,
            'process'        => $this->process->toArray(),
            'server'         => $this->server->toArray(),
            'storage'        => $this->storage->toArray(),
            'sender'         => $this->sender->toArray(),
            'pending_bytes'  => $this->pendingBytes,
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    public function toFlatArray(): array
    {
        $result = [];

        $walk = function (array $data, string $prefix) use (&$result, &$walk): void {
            foreach ($data as $key => $value) {
                $name = $prefix === ''
                    ? (string) $key
                    : $prefix . '.' . $key;

                if (is_array($value)) {
                    $walk($value, $name);
                } else {
                    $result[$name] = $value;
                }
            }
        };

        $walk($this->toArray(), '');

        /** @var array<string, string|int|float|bool|null> $result */
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    private static function parseTimestamp(array $data, string $key): Carbon
    {
        $timestamp = $data[$key] ?? null;

        // a strict RFC3339(Nano) shape with real calendar and clock components:
        // Carbon::parse would otherwise accept "now", and normalize an
        // impossible date like 2026-02-30 into March
        if (!is_string($timestamp)
            || !preg_match(self::TIMESTAMP_PATTERN, $timestamp, $matches)
            || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
            || (int) $matches[4] > 23
            || (int) $matches[5] > 59
            || (int) $matches[6] > 59
        ) {
            throw new InvalidArgumentException("metrics snapshot has no valid $key");
        }

        if (preg_match(self::TIMESTAMP_OFFSET_PATTERN, $timestamp, $offsetMatches)
            && ((int) $offsetMatches[2] > 23 || (int) $offsetMatches[3] > 59)
        ) {
            throw new InvalidArgumentException("metrics snapshot has no valid $key");
        }

        try {
            return Carbon::parse($timestamp);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException("metrics snapshot has no valid $key", 0, $exception);
        }
    }
}
