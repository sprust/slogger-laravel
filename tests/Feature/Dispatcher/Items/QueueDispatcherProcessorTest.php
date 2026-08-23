<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Items;

use SLoggerLaravel\Dispatcher\Items\Queue\QueueDispatcherProcessor;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class QueueDispatcherProcessorTest extends BaseTestCase
{
    /**
     * Without `exec` the shell stays between the master and the worker: the pid the
     * master saves and signals is the shell's, `sh` does not forward signals, and the
     * worker - sharing the master's process group, which the group-kill guard skips -
     * survives the master and keeps draining the queue.
     */
    public function testTheWorkerIsExecedSoThePidIsTheWorkersOwn(): void
    {
        $process = $this->getApp()->make(QueueDispatcherProcessor::class)->createProcess();

        self::assertStringStartsWith('exec ', $process->getCommandLine());

        // and it is still the worker command, not something else
        self::assertStringContainsString('artisan', $process->getCommandLine());
        self::assertStringContainsString('--queue=', $process->getCommandLine());
    }

    public function testEveryWorkerIsCreatedTheSameWay(): void
    {
        $processor = $this->getApp()->make(QueueDispatcherProcessor::class);

        foreach ($processor->createProcesses() as $process) {
            self::assertStringStartsWith('exec ', $process->getCommandLine());
        }
    }
}
