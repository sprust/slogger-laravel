<?php

namespace SLoggerLaravel\Dispatcher\Items\Transporter;

use SLoggerLaravel\Dispatcher\Items\DispatcherProcessorInterface;
use Symfony\Component\Process\Process;

readonly class TransporterDispatcherProcessor implements DispatcherProcessorInterface
{
    public function __construct(
        private TransporterProcess $transporterProcess
    ) {
    }

    public function createProcesses(): array
    {
        return [
            $this->createProcess(),
        ];
    }

    public function createProcess(): Process
    {
        return $this->transporterProcess->createProcess('start');
    }
}
