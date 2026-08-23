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

    /**
     * The command line a started worker shows in the process table - what the kernel
     * will show, not what was asked for. The master looks its children up by it.
     */
    public function getChildCommandName(): string;
}
