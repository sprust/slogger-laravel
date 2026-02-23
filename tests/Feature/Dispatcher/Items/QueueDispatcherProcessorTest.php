<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Items;

use Illuminate\Contracts\Container\BindingResolutionException;
use SLoggerLaravel\Dispatcher\Items\Queue\QueueDispatcherProcessor;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class QueueDispatcherProcessorTest extends BaseTestCase
{
    /**
     * @throws BindingResolutionException
     */
    public function testCreateProcessesBuildsWorkers(): void
    {
        config()->set('slogger.dispatchers.queue.workers_num', 2);
        config()->set('slogger.dispatchers.queue.connection', 'sync');
        config()->set('slogger.dispatchers.queue.name', 'slogger');

        $processor = $this->getApp()->make(QueueDispatcherProcessor::class);

        $processes = $processor->createProcesses();

        self::assertCount(2, $processes);

        $commandLine = $processes[0]->getCommandLine();

        self::assertStringContainsString('artisan', $commandLine);
        self::assertStringContainsString('queue:work', $commandLine);
        self::assertStringContainsString('--queue=slogger', $commandLine);
    }
}
