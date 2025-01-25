<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue\Jobs;

use SLoggerLaravel\Dispatcher\Items\Queue\ApiClients\ApiClientInterface;
use SLoggerLaravel\Objects\TraceUpdateObjects;

class TraceUpdateJob extends AbstractSLoggerTraceJob
{
    public function __construct(
        private readonly string $traceObjectsJson,
    ) {
        parent::__construct();
    }

    protected function onHandle(ApiClientInterface $apiClient): void
    {
        $apiClient->updateTraces(
            TraceUpdateObjects::fromJson($this->traceObjectsJson)
        );
    }
}
