<?php

namespace SLoggerLaravel\Events;

use SLoggerLaravel\LocalProxy\Objects\LocalProxyMetricsObject;

class LocalProxyMetricsUnavailableEvent
{
    public function __construct(
        public readonly string $reason,
        public readonly ?LocalProxyMetricsObject $metrics = null,
    ) {
    }
}
