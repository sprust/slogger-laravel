<?php

namespace SLoggerLaravel\Dispatcher\ApiClients;

use SLoggerLaravel\Objects\TracesObject;

interface ApiClientInterface
{
    public function sendTraces(TracesObject $traces): void;
}
