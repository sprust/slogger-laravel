<?php

namespace SLoggerLaravel\Events;

use SLoggerLaravel\LocalProxy\Objects\LocalProxyMetricsObject;

class LocalProxyBacklogExceededEvent
{
    public function __construct(
        public readonly int $pendingBytes,
        public readonly int $thresholdBytes,
        public readonly LocalProxyMetricsObject $metrics,
    ) {
    }
}
