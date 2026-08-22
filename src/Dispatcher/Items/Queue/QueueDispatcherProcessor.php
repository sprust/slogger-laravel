<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue;

use Illuminate\Queue\Console\WorkCommand;
use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Dispatcher\Items\DispatcherProcessorInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

readonly class QueueDispatcherProcessor implements DispatcherProcessorInterface
{
    private int $workersNum;
    private string $workerCommand;

    public function __construct(DispatcherQueueConfig $config)
    {
        $this->workersNum = $config->getWorkersNum();

        // tries/backoff are not passed here on purpose:
        // the values set on SendTracesJob (from config) are serialized
        // into the job payload and take precedence over worker options.
        $this->workerCommand = sprintf(
            '%s %s/artisan %s %s --queue=%s',
            (new PhpExecutableFinder)->find(),
            base_path(),
            app(WorkCommand::class)->getName(),
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
     * `exec` is load-bearing.
     *
     * `fromShellCommandline()` runs the command through `sh -c`, so the pid Symfony
     * reports - the pid the master saves and signals - belongs to the shell, not to
     * the worker it forked. `sh` does not forward signals, and the worker shares the
     * master's process group, which the group-kill guard skips. The master would
     * therefore kill the shell, see the process gone, report "worker processes are
     * stopped", and leave an orphaned `queue:work` draining the queue - one more per
     * restart. With `exec` the shell replaces itself with the worker, so the pid is
     * the worker's.
     */
    public function createProcess(): Process
    {
        return Process::fromShellCommandline('exec ' . $this->workerCommand)
            ->setTimeout(null);
    }
}
