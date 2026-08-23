<?php

namespace SLoggerLaravel\Dispatcher;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\Items\DispatcherFactory;
use SLoggerLaravel\Dispatcher\Items\DispatcherProcessorInterface;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessState;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessStateDto;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\Process;
use Throwable;

class Dispatcher
{
    private const RESTARTS_BEFORE_BACKOFF = 3;

    private const MAX_RESTART_DELAY_SECONDS = 30;

    /** How long a worker must stay up before its slot counts as settled. */
    private const SETTLED_UPTIME_SECONDS = 60;

    /** How long the master waits for its workers to finish the job in hand. */
    private const STOP_WAIT_SECONDS = 10;

    private bool $enabled;
    private bool $shouldQuit = false;

    /** What this master is supervising, set once by start(). */
    private string $dispatcherName   = '';
    private int $masterPid           = 0;
    private string $childCommandName = '';

    /**
     * Consecutive restarts per slot, for the backoff below.
     *
     * @var array<int, int>
     */
    private array $restartFailures = [];

    /**
     * When a slot backing off may be filled again.
     *
     * @var array<int, int>
     */
    private array $restartNotBefore = [];

    /**
     * When the worker now in a slot was started, for SETTLED_UPTIME_SECONDS.
     *
     * @var array<int, int>
     */
    private array $slotStartedAt = [];

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
        $this->dispatcherName = $dispatcher;
        $this->masterPid      = $this->processHelper->getCurrentPid();

        // before the takeover: stop() signals a pid read from a file, and a master
        // without handlers yet would die of its own signal
        pcntl_async_signals(true);

        pcntl_signal(SIGINT, fn() => $this->shouldQuit = true);
        pcntl_signal(SIGTERM, fn() => $this->shouldQuit = true);

        // before the takeover: create() throws, and killing the incumbent first left
        // the queue undrained and nothing running
        $processor = $this->enabled
            ? $this->dispatcherFactory->create($dispatcher)->getProcessor()
            : null;

        /** @var Process[] $processes */
        $processes = $processState->withLock(function () use ($processState, $processor): array {
            if ($previousState = $processState->getSaved()) {
                $this->stop($previousState);

                // not purged here: a crash before the new state is saved would leave
                // the workers with no file to be found by. The save overwrites it

                $this->logInfo(
                    sprintf(
                        "previous dispatcher[%s, pid: %s] stopped",
                        $previousState->dispatcher,
                        $previousState->masterPid
                    )
                );
            }

            $this->logInfo('starting...');

            if (is_null($processor)) {
                $this->saveState($processState, [], childCommandName: 'disabled');

                return [];
            }

            $started = $processor->createProcesses();

            if (!$started) {
                $this->fail('processes count is 0');
            }

            $this->childCommandName = $processor->getChildCommandName();

            foreach ($started as $index => $process) {
                $process->start();

                $this->slotStartedAt[$index] = time();

                $this->logInfo("child process started with PID {$process->getPid()}");
            }

            $this->saveState($processState, $started);

            return $started;
        });

        if (is_null($processor)) {
            $this->idleWhileDisabled($processState);

            return;
        }

        $this->logInfo('started');

        $this->shutdown(
            $processState,
            $this->supervise($processState, $processor, $processes)
        );
    }

    public function stop(DispatcherProcessStateDto $state): void
    {
        if ($this->processHelper->isPidActive($state->masterPid, $state->masterCommandName)) {
            $this->processHelper->sendStopSignal($state->masterPid);

            $this->writeInfo(
                $this->makeLogMessage($state->dispatcher, $state->masterPid, 'stop signal sent')
            );
        } else {
            $this->writeError(
                $this->makeLogMessage($state->dispatcher, $state->masterPid, 'already stopped')
            );
        }

        foreach ($state->childProcessPids as $childProcessPid) {
            if (!$childProcessPid) {
                continue;
            }

            if ($this->processHelper->isPidActive($childProcessPid, $state->childCommandName)) {
                $this->processHelper->sendStopSignal($childProcessPid);

                $this->writeInfo(
                    $this->makeLogMessage(
                        $state->dispatcher,
                        $state->masterPid,
                        "stop signal sent to child[pid: $childProcessPid]"
                    )
                );
            } else {
                $this->writeError(
                    $this->makeLogMessage(
                        $state->dispatcher,
                        $state->masterPid,
                        "dispatcher child [pid: $childProcessPid] already stopped"
                    )
                );
            }
        }
    }

    /**
     * Keeps every slot filled until asked to quit.
     *
     * @param Process[] $processes
     *
     * @return Process[]
     */
    private function supervise(
        DispatcherProcessState $processState,
        DispatcherProcessorInterface $processor,
        array $processes
    ): array {
        while (!$this->shouldQuit) {
            foreach ($processes as $index => $process) {
                // read it before replacing it: a worker that died says why on
                // its way out
                $this->readProcessOutput($process);

                if ($process->isRunning()) {
                    $this->settleSlot($index);

                    continue;
                }

                if (!$this->mayRefillSlot($index)) {
                    continue;
                }

                try {
                    $restartedProcess = $processor->createProcess();
                    $restartedProcess->start();

                    $processes[$index]           = $restartedProcess;
                    $this->slotStartedAt[$index] = time();

                    $this->saveState($processState, $processes);
                } catch (Throwable $exception) {
                    // per slot: one throw used to end supervision for good, and the
                    // command still exited 0
                    $this->logError($exception->getMessage());

                    continue;
                }

                $this->logInfo("child process restarted with PID {$restartedProcess->getPid()}");
            }

            sleep(1);
        }

        return $processes;
    }

    /**
     * Asks every worker to finish, waits for it, and gives up the state file.
     *
     * @param Process[] $processes
     */
    private function shutdown(DispatcherProcessState $processState, array $processes): void
    {
        foreach ($processes as $process) {
            if (!$process->isRunning()) {
                continue;
            }

            $this->processHelper->sendStopSignal(
                $process->getPid() ?? throw new RuntimeException('Process has no PID')
            );
        }

        $waitingSince = time();

        while ((time() - $waitingSince) < self::STOP_WAIT_SECONDS) {
            foreach ($processes as $index => $process) {
                $this->readProcessOutput($process);

                if ($process->isRunning()) {
                    continue;
                }

                unset($processes[$index]);
            }

            if (!$processes) {
                break;
            }

            // both calls above are non-blocking: without this the master burns a
            // core for the whole wait, which is the normal case for a graceful stop
            usleep(100000);
        }

        foreach ($processes as $process) {
            $this->readProcessOutput($process);
        }

        if ($processes) {
            $this->fail('failed to stop worker processes');
        }

        $this->logInfo('worker processes are stopped');

        $processState->purgeIfOwnedBy($this->masterPid);
    }

    /**
     * A disabled dispatcher still holds the state file and answers `stop`.
     */
    private function idleWhileDisabled(DispatcherProcessState $processState): void
    {
        $message = 'SLogger is disabled';

        // said at once, not eleven seconds in
        $logTime = 0;

        while (!$this->shouldQuit) {
            if ((time() - $logTime) > 10) {
                $logTime = time();

                $this->logger->warning($message);
                $this->output->writeln($message);
            }

            sleep(1);
        }

        // as the enabled path does: a file naming a dead master misleads both commands
        $processState->purgeIfOwnedBy($this->masterPid);
    }

    /**
     * Whether the slot's replacement is due yet.
     *
     * A deadline rather than a sleep(): sleeping left the healthy slots unwatched and
     * their output unread, and 64KB of pipe blocks a worker on printing.
     */
    private function mayRefillSlot(int $index): bool
    {
        if (isset($this->restartNotBefore[$index])) {
            if (time() < $this->restartNotBefore[$index]) {
                return false;
            }

            unset($this->restartNotBefore[$index]);

            return true;
        }

        $delay = $this->restartDelayFor($index);

        if ($delay <= 0) {
            return true;
        }

        $this->restartNotBefore[$index] = time() + $delay;

        return false;
    }

    /**
     * Not on the first tick that finds it running - that is a second after the
     * restart, and one dying two seconds in never reached the backoff.
     */
    private function settleSlot(int $index): void
    {
        if (($this->restartFailures[$index] ?? 0) === 0) {
            return;
        }

        $startedAt = $this->slotStartedAt[$index] ?? null;

        if (is_null($startedAt) || (time() - $startedAt) < self::SETTLED_UPTIME_SECONDS) {
            return;
        }

        $this->restartFailures[$index] = 0;
    }

    /**
     * Counted per slot and cleared by settleSlot(), so an occasional crash restarts
     * immediately and a boot loop backs off.
     */
    private function restartDelayFor(int $index): int
    {
        $failures = ($this->restartFailures[$index] ?? 0) + 1;

        $this->restartFailures[$index] = $failures;

        if ($failures <= self::RESTARTS_BEFORE_BACKOFF) {
            return 0;
        }

        return (int) min(
            self::MAX_RESTART_DELAY_SECONDS,
            2 ** ($failures - self::RESTARTS_BEFORE_BACKOFF)
        );
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
    private function saveState(
        DispatcherProcessState $processState,
        array $childProcesses,
        ?string $childCommandName = null
    ): void {
        $processState->save(
            new DispatcherProcessStateDto(
                dispatcher: $this->dispatcherName,
                masterCommandName: $processState->getMasterCommandName(),
                masterPid: $this->masterPid,
                childCommandName: $childCommandName ?? $this->childCommandName,
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
        $this->writeInfo(
            $this->makeLogMessage($this->dispatcherName, $this->masterPid, $message)
        );
    }

    private function logError(string $message): void
    {
        $this->writeError(
            $this->makeLogMessage($this->dispatcherName, $this->masterPid, $message)
        );
    }

    /** Reports what the master cannot carry on from, and throws the same text. */
    private function fail(string $message): never
    {
        $decorated = $this->makeLogMessage($this->dispatcherName, $this->masterPid, $message);

        $this->writeError($decorated);

        throw new RuntimeException($decorated);
    }

    private function writeInfo(string $message): void
    {
        $this->output->writeln($message);
        $this->logger->info($message);
    }

    private function writeError(string $message): void
    {
        $this->output->writeln($message);
        $this->logger->error($message);
    }
}
