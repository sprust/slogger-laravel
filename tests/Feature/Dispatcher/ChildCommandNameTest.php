<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher;

use SLoggerLaravel\Dispatcher\Items\Queue\QueueDispatcherProcessor;
use SLoggerLaravel\Dispatcher\ProcessHelper;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use Symfony\Component\Process\Process;

/**
 * The name saved for a worker has to be the one /proc will show, or a master that
 * comes back can neither find nor stop its children.
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

            // what a processor hands over: the command without the shell prefix
            $saved = 'sleep 5';

            // the whole point: this is what the master stores and later looks up by
            self::assertTrue($helper->isPidActive($pid, $saved));

            // the unstripped command line matches nothing: `exec` is what removes
            // the shell from /proc
            self::assertFalse($helper->isPidActive($pid, $process->getCommandLine()));
        } finally {
            $process->stop(0, SIGKILL);
        }
    }

    public function testTheWorkerCommandIsExecedAndTheSavedNameIsNot(): void
    {
        $processor = $this->getApp()->make(QueueDispatcherProcessor::class);

        $commandLine = $processor->createProcess()->getCommandLine();

        // without it the reported pid is the shell's, and `sh` forwards no signals
        self::assertStringStartsWith('exec ', $commandLine);

        // and the processor says the saved name rather than the master guessing it
        self::assertSame(
            substr($commandLine, strlen('exec ')),
            $processor->getChildCommandName()
        );
    }
}
