<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher;

use SLoggerLaravel\Dispatcher\Items\Queue\QueueDispatcherProcessor;
use SLoggerLaravel\Dispatcher\ProcessHelper;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use Symfony\Component\Process\Process;

/**
 * The name saved for a worker has to be the one /proc will show, or the master can
 * neither find nor stop its children after it comes back.
 */
class ChildCommandNameTest extends BaseTestCase
{
    public function testTheSavedNameMatchesWhatProcShows(): void
    {
        $process = Process::fromShellCommandline('exec sleep 5');

        $process->start();

        try {
            // give the exec a moment to replace the shell
            usleep(200000);

            $pid = $process->getPid();

            self::assertIsInt($pid);

            $helper = new ProcessHelper();

            $saved = $this->stripExecPrefix($process->getCommandLine());

            self::assertSame('sleep 5', $saved);

            // the whole point: this is what the master stores and later looks up by
            self::assertTrue($helper->isPidActive($pid, $saved));

            // and the unstripped command line, which is what used to be stored,
            // matches nothing - `exec` is precisely what removes the shell from /proc
            self::assertFalse($helper->isPidActive($pid, $process->getCommandLine()));
        } finally {
            $process->stop(0, SIGKILL);
        }
    }

    public function testTheWorkerCommandIsExeced(): void
    {
        $commandLine = $this->getApp()->make(QueueDispatcherProcessor::class)
            ->createProcess()
            ->getCommandLine();

        // without it the reported pid is the shell's, and `sh` forwards no signals
        self::assertStringStartsWith('exec ', $commandLine);
    }

    private function stripExecPrefix(string $commandLine): string
    {
        $method = (new \ReflectionClass(\SLoggerLaravel\Dispatcher\Dispatcher::class))
            ->getMethod('stripExecPrefix');

        /** @var string $stripped */
        $stripped = $method->invoke(null, $commandLine);

        return $stripped;
    }
}
