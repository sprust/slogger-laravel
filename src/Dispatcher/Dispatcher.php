<?php

namespace SLoggerLaravel\Dispatcher;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\Items\DispatcherFactory;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessState;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessStateDto;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\Process;
use Throwable;

class Dispatcher
{
    private bool $enabled;
    private bool $shouldQuit = false;
    private LoggerInterface $logger;

    public function __construct(
        private readonly ConsoleOutput $output,
        private readonly ProcessHelper $processHelper,
        private readonly DispatcherFactory $dispatcherFactory,
        private readonly GeneralConfig $generalConfig,
    ) {
        $this->enabled = $generalConfig->isEnabled();
        $this->logger  = Log::channel($this->generalConfig->getLogChannel());
    }

    /**
     * @throws BindingResolutionException
     */
    public function start(DispatcherProcessState $processState, string $dispatcher): void
    {
        $masterPid = $this->processHelper->getCurrentPid();

        if ($previousState = $processState->getSaved()) {
            $this->stop($previousState);

            $processState->purge();

            $this->logInfo(
                $this->makeLogMessage(
                    dispatcher: $dispatcher,
                    masterPid: $masterPid,
                    message: sprintf(
                        "previous dispatcher[%s, pid: %s] stopped",
                        $previousState->dispatcher,
                        $previousState->masterPid
                    )
                )
            );
        }

        $this->logInfo(
            $this->makeLogMessage(
                dispatcher: $dispatcher,
                masterPid: $masterPid,
                message: "starting..."
            )
        );

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, fn() => $this->shouldQuit = true);
        pcntl_signal(SIGTERM, fn() => $this->shouldQuit = true);

        if (!$this->enabled) {
            $this->freshState(
                processState: $processState,
                dispatcher: $dispatcher,
                masterPid: $masterPid,
                childCommandName: 'disabled',
                childProcesses: []
            );

            $message = 'SLogger is disabled';

            $logTime = time();

            while (!$this->shouldQuit) {
                if ((time() - $logTime) > 10) {
                    $logTime = time();

                    $this->logger->warning($message);
                    $this->output->writeln($message);
                }

                sleep(1);
            }

            return;
        }

        $processor = $this->dispatcherFactory->create($dispatcher)->getProcessor();

        $processes = $processor->createProcesses();

        $processesCount = count($processes);

        if (!$processesCount) {
            $message = $this->makeLogMessage(
                dispatcher: $dispatcher,
                masterPid: $masterPid,
                message: 'processes count is 0'
            );

            $this->logError($message);

            throw new RuntimeException($message);
        }

        $childCommandName = $processes[0]->getCommandLine();

        foreach ($processes as $process) {
            $process->start();

            $this->logInfo(
                $this->makeLogMessage(
                    dispatcher: $dispatcher,
                    masterPid: $masterPid,
                    message: "child process started with PID {$process->getPid()}"
                )
            );
        }

        $this->freshState(
            processState: $processState,
            dispatcher: $dispatcher,
            masterPid: $masterPid,
            childCommandName: $childCommandName,
            childProcesses: $processes
        );

        $this->logInfo(
            $this->makeLogMessage(
                dispatcher: $dispatcher,
                masterPid: $masterPid,
                message: 'started'
            )
        );

        try {
            while (!$this->shouldQuit) {
                foreach ($processes as $index => $process) {
                    if ($process->isRunning()) {
                        $this->readProcessOutput($process);

                        continue;
                    }

                    $restartedProcess = $processor->createProcess();
                    $restartedProcess->start();

                    $processes[$index] = $restartedProcess;

                    $this->freshState(
                        processState: $processState,
                        dispatcher: $dispatcher,
                        masterPid: $masterPid,
                        childCommandName: $childCommandName,
                        childProcesses: $processes
                    );

                    $this->logInfo(
                        $this->makeLogMessage(
                            dispatcher: $dispatcher,
                            masterPid: $masterPid,
                            message: "child process restarted with PID {$restartedProcess->getPid()}"
                        )
                    );
                }

                sleep(1);
            }
        } catch (Throwable $exception) {
            $this->logError(
                $this->makeLogMessage(
                    dispatcher: $dispatcher,
                    masterPid: $masterPid,
                    message: $exception->getMessage()
                )
            );
        }

        foreach ($processes as $process) {
            if (!$process->isRunning()) {
                continue;
            }

            $this->processHelper->sendStopSignal($process->getPid());
        }

        $startTimeOfWaitFinishingProcesses = time();

        while ((time() - $startTimeOfWaitFinishingProcesses) < 10) {
            foreach ($processes as $index => $process) {
                $this->readProcessOutput($process);

                if ($process->isRunning()) {
                    continue;
                }

                unset($processes[$index]);
            }

            $processesCount = count($processes);

            if (!$processesCount) {
                break;
            }
        }

        foreach ($processes as $process) {
            $this->readProcessOutput($process);
        }

        if (!$processesCount) {
            $this->logInfo(
                $this->makeLogMessage(
                    dispatcher: $dispatcher,
                    masterPid: $masterPid,
                    message: 'worker processes are stopped'
                )
            );
        } else {
            $message = $this->makeLogMessage(
                dispatcher: $dispatcher,
                masterPid: $masterPid,
                message: 'failed to stop worker processes'
            );

            $this->logError($message);

            throw new RuntimeException($message);
        }

        $processState->purge();
    }

    public function stop(DispatcherProcessStateDto $state): void
    {
        if ($this->processHelper->isPidActive($state->masterPid, $state->masterCommandName)) {
            $this->processHelper->sendStopSignal($state->masterPid);

            $this->logInfo(
                $this->makeLogMessage(
                    dispatcher: $state->dispatcher,
                    masterPid: $state->masterPid,
                    message: 'stop signal sent'
                )
            );
        } else {
            $this->logError(
                $this->makeLogMessage(
                    dispatcher: $state->dispatcher,
                    masterPid: $state->masterPid,
                    message: 'already stopped'
                )
            );
        }

        foreach ($state->childProcessPids as $childProcessPid) {
            if (!$childProcessPid) {
                continue;
            }

            if ($this->processHelper->isPidActive($childProcessPid, $state->childCommandName)) {
                $this->processHelper->sendStopSignal($childProcessPid);
                $this->logInfo(
                    $this->makeLogMessage(
                        dispatcher: $state->dispatcher,
                        masterPid: $state->masterPid,
                        message: "stop signal sent to child[pid: $childProcessPid]"
                    )
                );
            } else {
                $this->logError(
                    $this->makeLogMessage(
                        dispatcher: $state->dispatcher,
                        masterPid: $state->masterPid,
                        message: "dispatcher child [pid: $childProcessPid] already stopped"
                    )
                );
            }
        }
    }

    private function readProcessOutput(Process $process): void
    {
        $output = [
            $process->getIncrementalOutput(),
            $process->getIncrementalErrorOutput(),
        ];

        $process->clearOutput()->clearErrorOutput();

        $message = trim(implode(PHP_EOL, array_filter($output)), PHP_EOL);

        if (!$message) {
            return;
        }

        $this->output->writeln($message);
    }

    /**
     * @param Process[] $childProcesses
     */
    private function freshState(
        DispatcherProcessState $processState,
        string $dispatcher,
        int $masterPid,
        string $childCommandName,
        array $childProcesses
    ): void {
        $processState->save(
            new DispatcherProcessStateDto(
                dispatcher: $dispatcher,
                masterCommandName: $processState->getMasterCommandName(),
                masterPid: $masterPid,
                childCommandName: $childCommandName,
                childProcessPids: array_values(
                    array_filter(
                        array_map(
                            static fn(Process $process) => $process->getPid(),
                            $childProcesses
                        )
                    )
                )
            )
        );
    }

    private function makeLogMessage(string $dispatcher, int $masterPid, string $message): string
    {
        return "dispatcher[name: $dispatcher, pid: $masterPid]: $message";
    }

    private function logInfo(string $message): void
    {
        $this->output->writeln($message);
        $this->logger->info($message);
    }

    private function logError(string $message): void
    {
        $this->output->writeln($message);
        $this->logger->error($message);
    }
}
