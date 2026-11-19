<?php

namespace SLoggerLaravel\LocalProxy\Objects;

class LocalProxyProcessMetricsObject
{
    public function __construct(
        public readonly int $goroutines,
        public readonly int $heapAllocBytes,
        public readonly int $heapSysBytes,
        public readonly int $stackInuseBytes,
        public readonly ?int $rssBytes,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            goroutines: LocalProxyMetricsSchema::int($data, 'goroutines', 'process.'),
            heapAllocBytes: LocalProxyMetricsSchema::int($data, 'heap_alloc_bytes', 'process.'),
            heapSysBytes: LocalProxyMetricsSchema::int($data, 'heap_sys_bytes', 'process.'),
            stackInuseBytes: LocalProxyMetricsSchema::int($data, 'stack_inuse_bytes', 'process.'),
            // omitempty on the Go side: a zero rss is not written at all
            rssBytes: LocalProxyMetricsSchema::optionalInt($data, 'rss_bytes', 'process.'),
        );
    }

    /**
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'goroutines'        => $this->goroutines,
            'heap_alloc_bytes'  => $this->heapAllocBytes,
            'heap_sys_bytes'    => $this->heapSysBytes,
            'stack_inuse_bytes' => $this->stackInuseBytes,
            'rss_bytes'         => $this->rssBytes,
        ];
    }
}
