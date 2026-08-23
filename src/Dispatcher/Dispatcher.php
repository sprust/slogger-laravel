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

    /**
     * How long a worker has to stay up before its slot counts as settled and the
     * restarts it took to get there are forgotten.
     */
    private const SETTLED_UPTIME_SECONDS = 60;

    /**
     * How long the master waits for its workers to finish the job in hand.
     */
    private const STOP_WAIT_SECONDS = 10;

    private bool $enabled;
    private bool $shouldQuit = false;

    /**
     * What this master is supervising, set once by start(). Everything it logs and
     * saves is named by these.
     */
    private string $dispatcherName   = '';
    private int $masterPid           = 0;
    private string $childCommandName = '';

    /**
     * Consecutive restarts per worker slot, for the backoff below.
     *
     * @var array<int, int>
     */
    private array $restartFailures = [];

    /**
     * When a slot backing off may be filled again, as a timestamp.
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

        // before the takeover, not after it: stop() signals a pid read from a file,
        // and a master that has not installed its handlers yet dies of its own
        // signal at the default action
        pcntl_async_signals(true);

        pcntl_signal(SIGINT, fn() => $this->shouldQuit = true);
        pcntl_signal(SIGTERM, fn() => $this->shouldQuit = true);

        if ($previousState = $processState->getSaved()) {
            $this->stop($previousState);

            // not purged here: between this point and the moment the new state is
            // saved, a crash would leave the workers about to be started with no file
            // to be found by. The new state overwrites this one atomically anyway

            $this->logInfo(
                sprintf(
                    "previous dispatcher[%s, pid: %s] stopped",
                    $previousState->dispatcher,
                    $previousState->masterPid
                )
            );
        }

        $this->logInfo('starting...');

        if (!$this->enabled) {
            $this->idleWhileDisabled($processState);

            return;
        }

        $processor = $this->dispatcherFactory->create($dispatcher)->getProcessor();

        $processes = $processor->createProcesses();

        if (!$processes) {
            $this->fail('processes count is 0');
        }

        $this->childCommandName = $processor->getChildCommandName();

        foreach ($processes as $index => $process) {
            $process->start();

            $this->slotStartedAt[$index] = time();

            $this->logInfo("child process started with PID {$process->getPid()}");
        }

        $this->saveState($processState, $processes);

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
     * Keeps every slot filled until asked to quit, and hands back what is in them.
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
        try {
            while (!$this->shouldQuit) {
                foreach ($processes as $index => $process) {
                    // read what it said before replacing it: a worker that died says
                    // why on its way out, and dropping that leaves a restart loop
                    // with no explanation anywhere
                    $this->readProcessOutput($process);

                    if ($process->isRunning()) {
                        $this->settleSlot($index);

                        continue;
                    }

                    if (!$this->mayRefillSlot($index)) {
                        continue;
                    }

                    $restartedProcess = $processor->createProcess();
                    $restartedProcess->start();

                    $processes[$index]           = $restartedProcess;
                    $this->slotStartedAt[$index] = time();

                    $this->saveState($processState, $processes);

                    $this->logInfo("child process restarted with PID {$restartedProcess->getPid()}");
                }

                sleep(1);
            }
        } catch (Throwable $exception) {
            $this->logError($exception->getMessage());
        }

        return $processes;
    }

    /**
     * Asks every worker to finish what it is doing, waits for it, and gives up the
     * state file.
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

            // isRunning() and readProcessOutput() are both non-blocking, so without
            // this the master burns a core for the whole ten seconds every time a
            // worker takes a moment to finish its job - which is the normal case for
            // a graceful stop, and for every takeover
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
     * A disabled dispatcher still holds the state file and still answers `stop`:
     * turning SLogger off must not leave a command that cannot be managed.
     */
    private function idleWhileDisabled(DispatcherProcessState $processState): void
    {
        $this->saveState($processState, [], childCommandName: 'disabled');

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
    }

    /**
     * Whether the slot's replacement is due yet.
     *
     * A deadline rather than a sleep(): sleeping here stopped the whole supervision
     * loop, so while one slot waited its turn the healthy ones went unwatched and
     * their output unread - and 64KB of unread pipe is all it takes for a worker to
     * block on printing "Processed". Delays added up across slots, too, and a
     * signal arriving mid-sleep still started one more worker before quitting.
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
     * Forgets a slot's restart count once the worker in it has been up long enough
     * to call it settled.
     *
     * Not on the first tick that finds it running: that is a second after the
     * restart, so a worker dying two seconds in - a Redis connect timing out during
     * boot, a crash on the first job - cleared the count every single time and never
     * reached the backoff at all.
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
     * How long to wait before replacing a worker that keeps dying.
     *
     * Counted per slot, and cleared by settleSlot() once one stays up, so an
     * occasional crash restarts immediately and a boot loop backs off.
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

    /**
     * Reports what the master cannot carry on from, in both places it is looked for,
     * and hands the same text to the caller.
     */
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
