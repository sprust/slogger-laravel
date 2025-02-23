<?php

namespace SLoggerLaravel\Dispatcher\Items;

use SLoggerLaravel\Objects\TraceObject;
use SLoggerLaravel\Objects\TraceUpdateObject;

interface TraceDispatcherInterface
{
    public function getProcessor(): DispatcherProcessorInterface;

    public function create(TraceObject $parameters): void;

    public function update(TraceUpdateObject $parameters): void;

    public function terminate(): void;
}
