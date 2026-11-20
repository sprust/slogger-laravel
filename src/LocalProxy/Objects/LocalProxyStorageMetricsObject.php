<?php

namespace SLoggerLaravel\LocalProxy\Objects;

class LocalProxyStorageMetricsObject
{
    public function __construct(
        public readonly int $hourlyFiles,
        public readonly int $hourlyBytes,
        public readonly int $errorLogFiles,
        public readonly int $appendedRecordsTotal,
        public readonly int $appendedBytesTotal,
        public readonly ?string $oldestHourlyFile,
        public readonly ?string $newestHourlyFile,
        public readonly LocalProxyStorageStateObject $state,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hourlyFiles: LocalProxyMetricsSchema::int($data, 'hourly_files', 'storage.'),
            hourlyBytes: LocalProxyMetricsSchema::int($data, 'hourly_bytes', 'storage.'),
            errorLogFiles: LocalProxyMetricsSchema::int($data, 'error_log_files', 'storage.'),
            appendedRecordsTotal: LocalProxyMetricsSchema::int($data, 'appended_records_total', 'storage.'),
            appendedBytesTotal: LocalProxyMetricsSchema::int($data, 'appended_bytes_total', 'storage.'),
            // omitempty on the Go side: absent when there are no hourly files
            oldestHourlyFile: LocalProxyMetricsSchema::optionalString($data, 'oldest_hourly_file', 'storage.'),
            newestHourlyFile: LocalProxyMetricsSchema::optionalString($data, 'newest_hourly_file', 'storage.'),
            state: LocalProxyStorageStateObject::fromArray(
                LocalProxyMetricsSchema::section($data, 'state', 'storage.')
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hourly_files'           => $this->hourlyFiles,
            'hourly_bytes'           => $this->hourlyBytes,
            'error_log_files'        => $this->errorLogFiles,
            'appended_records_total' => $this->appendedRecordsTotal,
            'appended_bytes_total'   => $this->appendedBytesTotal,
            'oldest_hourly_file'     => $this->oldestHourlyFile,
            'newest_hourly_file'     => $this->newestHourlyFile,
            'state'                  => $this->state->toArray(),
        ];
    }
}
