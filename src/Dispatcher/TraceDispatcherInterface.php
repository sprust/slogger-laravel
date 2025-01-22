<?php

namespace SLoggerLaravel\Dispatcher;

use SLoggerLaravel\Objects\TraceObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use Symfony\Component\Console\Output\OutputInterface;

interface TraceDispatcherInterface
{
    public function start(OutputInterface $output): void;

    public function create(TraceObject $parameters): void;

    public function update(TraceUpdateObject $parameters): void;
}
