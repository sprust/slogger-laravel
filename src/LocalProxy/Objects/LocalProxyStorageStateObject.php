<?php

namespace SLoggerLaravel\LocalProxy\Objects;

class LocalProxyStorageStateObject
{
    public function __construct(
        public readonly ?string $file,
        public readonly int $offset,
        public readonly bool $completed,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $file = LocalProxyMetricsSchema::optionalString($data, 'file', 'storage.state.');

        return new self(
            // omitempty on the Go side: an empty file name is not written at
            // all, and an empty one reads as "no file" (as Go's Load treats it)
            file: $file === '' || $file === null ? null : $file,
            offset: LocalProxyMetricsSchema::nonNegativeInt($data, 'offset', 'storage.state.'),
            completed: LocalProxyMetricsSchema::bool($data, 'completed', 'storage.state.'),
        );
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function toArray(): array
    {
        return [
            'file'      => $this->file,
            'offset'    => $this->offset,
            'completed' => $this->completed,
        ];
    }
}
