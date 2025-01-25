<?php

namespace SLoggerLaravel\Dispatcher\Items;

use Symfony\Component\Process\Process;

interface DispatcherProcessorInterface
{
    /**
     * Create not started processes
     *
     * @return Process[]
     */
    public function createProcesses(): array;

    /**
     * Create not started process
     */
    public function createProcess(): Process;
}
