<?php

namespace SLoggerLaravel\Dispatcher\Items;

use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;

interface TraceDispatcherInterface
{
    public function getProcessor(): DispatcherProcessorInterface;

    public function create(TraceCreateObject $parameters): void;

    public function update(TraceUpdateObject $parameters): void;

    public function terminate(): void;
}
