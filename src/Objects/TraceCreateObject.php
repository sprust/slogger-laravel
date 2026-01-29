<?php

namespace SLoggerLaravel\Objects;

use Illuminate\Support\Carbon;

class TraceCreateObject
{
    /**
     * @param string[]             $tags
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $traceId,
        public ?string $parentTraceId,
        public string $type,
        public string $status,
        public array $tags,
        public array $data,
        public ?float $duration,
        public ?float $memory,
        public ?float $cpu,
        public bool $isParent,
        public Carbon $loggedAt
    ) {
    }
}
