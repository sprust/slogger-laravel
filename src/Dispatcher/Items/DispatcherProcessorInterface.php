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
     * The command line a started worker shows in the process table.
     *
     * The master saves it and looks its children up by it afterwards, so it has to
     * be what the kernel will show rather than what was asked for - a shell prefix
     * the worker replaces itself with is the processor's own business, and knowing
     * about it here left the master parsing command lines it did not build.
     */
    public function getChildCommandName(): string;
}
