<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Items;

use SLoggerLaravel\Dispatcher\Items\Queue\QueueDispatcherProcessor;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class QueueDispatcherProcessorTest extends BaseTestCase
{
    /**
     * Without `exec` the pid the master saves is the shell's, `sh` forwards no
     * signals, and the worker survives to keep draining the queue.
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
