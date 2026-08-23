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

            // the shape a processor hands over: the command without the shell
            // prefix it replaces itself with
            $saved = 'sleep 5';

            // the whole point: this is what the master stores and later looks up by
            self::assertTrue($helper->isPidActive($pid, $saved));

            // and the unstripped command line, which is what used to be stored,
            // matches nothing - `exec` is precisely what removes the shell from /proc
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

        // and the name the master saves is the one /proc will show. The processor
        // says it rather than the master guessing it back out of the command line
        self::assertSame(
            substr($commandLine, strlen('exec ')),
            $processor->getChildCommandName()
        );
    }
}
