<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue\ApiClients;

use SLoggerLaravel\Objects\TracesObject;

interface ApiClientInterface
{
    public function sendTraces(TracesObject $traces): void;
}
