<?php

namespace SLoggerLaravel\Events;

use SLoggerLaravel\LocalProxy\Objects\LocalProxyMetricsObject;

class LocalProxySendStalledEvent
{
    public function __construct(
        public readonly int $minutesWithoutSend,
        public readonly int $thresholdMinutes,
        public readonly LocalProxyMetricsObject $metrics,
    ) {
    }
}
