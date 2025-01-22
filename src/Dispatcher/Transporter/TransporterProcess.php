<?php

declare(ticks=1);

namespace SLoggerLaravel\Dispatcher\Transporter;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use SLoggerLaravel\Dispatcher\Transporter\Commands\LoadTransporterCommand;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\Process;
use Throwable;

class TransporterProcess
{
    private bool $shouldQuit = false;

    public function __construct(
        private readonly ConsoleOutput $output,
        private readonly TransporterLoader $loader
    ) {
    }

    public function handle(string $commandName, ?string $env = null): int
    {
        $this->output->writeln("handling: $commandName");

        if (!$this->loader->fileExists()) {
            Artisan::call(LoadTransporterCommand::class, outputBuffer: $this->output);
        }

        $envFileName = $env ?? '.env.strans.' . Str::slug($commandName, '.');
        $envFilePath = base_path($envFileName);

        if ($commandName === 'start') {
            pcntl_async_signals(true);

            pcntl_signal(SIGINT, fn() => $this->shouldQuit = true);
            pcntl_signal(SIGTERM, fn() => $this->shouldQuit = true);
        }

        $this->initEnv($envFilePath);

        $command = "{$this->loader->getPath()} --env=$envFileName $commandName";

        $process = Process::fromShellCommandline($command)
            ->setTimeout(null);

        $process->start();

        while (!$process->isStarted()) {
            sleep(1);
        }

        $this->output->writeln("started: $command");

        while ($process->isRunning()) {
            if ($this->shouldQuit) {
                $startTime = time();

                while ($process->isRunning()) {
                    $pid = $process->getPid();

                    $pgid = posix_getpgid($pid);

                    posix_kill($pid, SIGTERM);
                    posix_kill(-$pgid, SIGTERM);

                    if (time() - $startTime > 5) {
                        $this->output->writeln('Force stopped');

                        break;
                    }

                    sleep(1);
                }

                break;
            }

            $this->readOutput($process);

            sleep(1);
        }

        $this->readOutput($process);

        try {
            unlink($envFilePath);
        } catch (Throwable) {
            // no action
        }

        $this->output->writeln("stopped: $command");

        return $process->getExitCode() ?? 1;
    }

    private function readOutput(Process $process): void
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

    private function initEnv(string $envFilePath): void
    {
        $evnValues = config('slogger.dispatchers.transporter.env');

        $content = '';

        foreach ($evnValues as $key => $value) {
            $content .= "$key=$value" . PHP_EOL;
        }

        file_put_contents($envFilePath, $content);
    }
}
