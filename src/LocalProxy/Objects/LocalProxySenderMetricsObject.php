<?php

namespace SLoggerLaravel\LocalProxy\Objects;

class LocalProxySenderMetricsObject
{
    public function __construct(
        public readonly int $sentBatchesTotal,
        public readonly int $sentOperationsTotal,
        public readonly int $sentBytesTotal,
        public readonly int $failedSendAttemptsTotal,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sentBatchesTotal: LocalProxyMetricsSchema::int($data, 'sent_batches_total', 'sender.'),
            sentOperationsTotal: LocalProxyMetricsSchema::int($data, 'sent_operations_total', 'sender.'),
            sentBytesTotal: LocalProxyMetricsSchema::int($data, 'sent_bytes_total', 'sender.'),
            failedSendAttemptsTotal: LocalProxyMetricsSchema::int($data, 'failed_send_attempts_total', 'sender.'),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'sent_batches_total'         => $this->sentBatchesTotal,
            'sent_operations_total'      => $this->sentOperationsTotal,
            'sent_bytes_total'           => $this->sentBytesTotal,
            'failed_send_attempts_total' => $this->failedSendAttemptsTotal,
        ];
    }
}
