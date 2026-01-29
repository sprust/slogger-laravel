<?php

namespace SLoggerLaravel\Objects;

use Illuminate\Support\Carbon;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;

class TraceUpdateObject
{
    /**
     * @param string[]|null             $tags
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public string $traceId,
        public string $status,
        public ?ProfilingObjects $profiling,
        public ?array $tags,
        public ?array $data,
        public ?float $duration,
        public ?float $memory,
        public ?float $cpu,
        public Carbon $parentLoggedAt
    ) {
    }
}
