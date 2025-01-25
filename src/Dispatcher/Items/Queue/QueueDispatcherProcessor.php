<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue;

use Illuminate\Queue\Console\WorkCommand;
use SLoggerLaravel\Dispatcher\Items\DispatcherProcessorInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

readonly class QueueDispatcherProcessor implements DispatcherProcessorInterface
{
    private int $workersNum;
    private string $workerCommand;

    public function __construct()
    {
        $this->workersNum = config('slogger.dispatchers.queue.workers_num');

        $this->workerCommand = sprintf(
            '%s %s/artisan %s %s --queue=%s --tries=%d --backoff=%d',
            (new PhpExecutableFinder)->find(),
            base_path(),
            app(WorkCommand::class)->getName(),
            config('slogger.dispatchers.queue.connection'),
            config('slogger.dispatchers.queue.name'),
            120,
            1
        );
    }

    public function createProcesses(): array
    {
        $processes = [];

        for ($index = 0; $index < $this->workersNum; $index++) {
            $processes[] = $this->createProcess();
        }

        return $processes;
    }

    public function createProcess(): Process
    {
        return Process::fromShellCommandline($this->workerCommand)
            ->setTimeout(null);
    }
}
