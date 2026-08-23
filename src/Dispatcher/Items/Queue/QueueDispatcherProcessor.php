<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue;

use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Dispatcher\Items\DispatcherProcessorInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

readonly class QueueDispatcherProcessor implements DispatcherProcessorInterface
{
    /**
     * @see \Illuminate\Queue\Console\WorkCommand
     */
    private const WORKER_COMMAND = 'queue:work';

    private int $workersNum;
    private string $workerCommand;

    public function __construct(DispatcherQueueConfig $config)
    {
        $this->workersNum = $config->getWorkersNum();

        // no tries/backoff: SendTracesJob carries its own in the payload, which takes
        // precedence over the worker's options
        $this->workerCommand = sprintf(
            '%s %s/artisan %s %s --queue=%s',
            (new PhpExecutableFinder)->find(),
            base_path(),
            self::WORKER_COMMAND,
            $config->getConnection(),
            $config->getName()
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

    /**
     * `exec` is load-bearing: without it `sh -c` is what Symfony reports the pid of,
     * and `sh` forwards no signals - the master would kill the shell, call the worker
     * stopped, and leave an orphan draining the queue.
     */
    public function createProcess(): Process
    {
        return Process::fromShellCommandline('exec ' . $this->workerCommand)
            ->setTimeout(null);
    }

    /** What /proc shows once the shell has replaced itself with the worker. */
    public function getChildCommandName(): string
    {
        return $this->workerCommand;
    }
}
