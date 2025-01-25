<?php

declare(ticks=1);

namespace SLoggerLaravel\Dispatcher\Items\Transporter;

use Illuminate\Support\Str;
use RuntimeException;
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

    public function start(?string $env = null): int
    {
        return $this->handle('start', $env);
    }

    public function stop(?string $env = null): int
    {
        return $this->handle('manage stop', $env);
    }

    public function stat(?string $env = null): int
    {
        return $this->handle('manage stat', $env);
    }

    public function createProcess(string $commandName, ?string $env = null): Process
    {
        if (!$this->loader->fileExists()) {
            throw new RuntimeException(
                "Transporter is not loaded. Run 'php artisan slogger:transporter:load' first"
            );
        }

        $envFileName = $env ?? '.env.strans.' . Str::slug($commandName, '.');
        $envFilePath = base_path($envFileName);

        $this->initEnv($envFilePath);

        $command = "{$this->loader->getPath()} --env=$envFileName $commandName";

        return Process::fromShellCommandline($command)
            ->setTimeout(null);
    }

    private function handle(string $commandName, ?string $env = null): int
    {
        $this->output->writeln("handling: $commandName");

        if (!$this->loader->fileExists()) {
            throw new RuntimeException(
                "Transporter is not loaded. Run 'php artisan slogger:transporter:load' first"
            );
        }

        $envFileName = $env ?? '.env.strans.' . Str::slug($commandName, '.');
        $envFilePath = base_path($envFileName);

        if ($commandName === 'start') {
            $this->stop($envFileName);

            pcntl_async_signals(true);

            pcntl_signal(SIGINT, fn() => $this->shouldQuit = true);
            pcntl_signal(SIGTERM, fn() => $this->shouldQuit = true);
        }

        $process = $this->createProcess($commandName, $envFileName);
        $process->start();

        while (!$process->isStarted()) {
            sleep(1);
        }

        $commandLine = $process->getCommandLine();

        $this->output->writeln("started: $commandLine");

        while ($process->isRunning()) {
            if ($this->shouldQuit) {
                $startTime = time();

                while ($process->isRunning()) {
                    $pid  = $process->getPid();
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

        $this->output->writeln("stopped: $commandLine");

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
