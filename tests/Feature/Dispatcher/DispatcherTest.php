<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher;

use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\Dispatcher;
use SLoggerLaravel\Dispatcher\Items\DispatcherFactory;
use SLoggerLaravel\Dispatcher\ProcessHelper;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessStateDto;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class DispatcherTest extends BaseTestCase
{
    public function testStopSendsSignalsForActiveProcesses(): void
    {
        $processHelper = $this->createMock(ProcessHelper::class);
        $processHelper->expects(self::exactly(2))
            ->method('isPidActive')
            ->willReturn(true);

        $processHelper->expects(self::exactly(2))
            ->method('sendStopSignal')
            ->with(
                self::callback(function ($arg) {
                    static $callCount = 0;

                    $expected = [1001, 2002];

                    return $arg === $expected[$callCount++];
                })
            );

        Log::shouldReceive('channel')
            ->andReturn(new NullLogger());

        $dispatcher = new Dispatcher(
            output: new ConsoleOutput(OutputInterface::VERBOSITY_QUIET),
            processHelper: $processHelper,
            dispatcherFactory: $this->createMock(DispatcherFactory::class),
            generalConfig: new GeneralConfig(),
        );

        $dispatcher->stop(
            new DispatcherProcessStateDto(
                dispatcher: 'queue',
                masterCommandName: 'slogger:dispatcher:start',
                masterPid: 1001,
                childCommandName: 'artisan queue:work',
                childProcessPids: [2002],
            )
        );
    }

    public function testStopIgnoresInactiveProcesses(): void
    {
        $processHelper = $this->createMock(ProcessHelper::class);
        $processHelper->expects(self::exactly(2))
            ->method('isPidActive')
            ->willReturn(false);

        $processHelper->expects(self::never())
            ->method('sendStopSignal');

        Log::shouldReceive('channel')
            ->andReturn(new NullLogger());

        $dispatcher = new Dispatcher(
            output: new ConsoleOutput(OutputInterface::VERBOSITY_QUIET),
            processHelper: $processHelper,
            dispatcherFactory: $this->createMock(DispatcherFactory::class),
            generalConfig: new GeneralConfig(),
        );

        $dispatcher->stop(
            new DispatcherProcessStateDto(
                dispatcher: 'queue',
                masterCommandName: 'slogger:dispatcher:start',
                masterPid: 1001,
                childCommandName: 'artisan queue:work',
                childProcessPids: [2002],
            )
        );
    }
}
