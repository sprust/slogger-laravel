<?php

namespace SLoggerLaravel\LocalProxy\Objects;

class LocalProxyServerMetricsObject
{
    public function __construct(
        public readonly int $connections,
        public readonly int $idleConnections,
        public readonly int $connectionSlotsUsed,
        public readonly int $connectionLimit,
        public readonly int $queuedRecords,
        public readonly int $queueCapacityRecords,
        public readonly int $inputBudgetUsedBytes,
        public readonly int $inputBudgetTotalBytes,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            connections: LocalProxyMetricsSchema::int($data, 'connections', 'server.'),
            idleConnections: LocalProxyMetricsSchema::int($data, 'idle_connections', 'server.'),
            connectionSlotsUsed: LocalProxyMetricsSchema::int($data, 'connection_slots_used', 'server.'),
            connectionLimit: LocalProxyMetricsSchema::int($data, 'connection_limit', 'server.'),
            queuedRecords: LocalProxyMetricsSchema::int($data, 'queued_records', 'server.'),
            queueCapacityRecords: LocalProxyMetricsSchema::int($data, 'queue_capacity_records', 'server.'),
            inputBudgetUsedBytes: LocalProxyMetricsSchema::int($data, 'input_budget_used_bytes', 'server.'),
            inputBudgetTotalBytes: LocalProxyMetricsSchema::int($data, 'input_budget_total_bytes', 'server.'),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'connections'              => $this->connections,
            'idle_connections'         => $this->idleConnections,
            'connection_slots_used'    => $this->connectionSlotsUsed,
            'connection_limit'         => $this->connectionLimit,
            'queued_records'           => $this->queuedRecords,
            'queue_capacity_records'   => $this->queueCapacityRecords,
            'input_budget_used_bytes'  => $this->inputBudgetUsedBytes,
            'input_budget_total_bytes' => $this->inputBudgetTotalBytes,
        ];
    }
}
