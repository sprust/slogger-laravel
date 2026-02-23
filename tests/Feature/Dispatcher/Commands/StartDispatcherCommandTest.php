<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use RuntimeException;
use SLoggerLaravel\Configs\DispatcherConfig;
use SLoggerLaravel\Dispatcher\Dispatcher;
use SLoggerLaravel\Dispatcher\StartDispatcherCommand;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessState;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessStateDto;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class StartDispatcherCommandTest extends BaseTestCase
{
    /**
     * @throws Throwable
     */
    public function testHandleUsesArgumentDispatcher(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects(self::once())
            ->method('start')
            ->with(
                self::isInstanceOf(DispatcherProcessState::class),
                'memory'
            );

        $command = new class extends StartDispatcherCommand {
            public function argument($key = null): string
            {
                return 'memory';
            }
        };

        $this->prepareCommand($command);

        $command->handle($dispatcher, new DispatcherConfig());
    }

    /**
     * @throws Throwable
     */
    public function testHandleUsesDefaultDispatcher(): void
    {
        config()->set('slogger.dispatchers.default', 'queue');

        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects(self::once())
            ->method('start')
            ->with(
                self::isInstanceOf(DispatcherProcessState::class),
                'queue'
            );

        $command = new class extends StartDispatcherCommand {
            public function argument($key = null): null
            {
                return null;
            }
        };

        $this->prepareCommand($command);

        $command->handle($dispatcher, new DispatcherConfig());
    }

    /**
     * @throws Throwable
     */
    public function testHandleStopsOnExceptionWhenStateSaved(): void
    {
        $masterCommandName = (new StartDispatcherCommand())->getName();

        self::assertNotNull($masterCommandName);

        $state = new DispatcherProcessState($masterCommandName);

        $state->save(
            new DispatcherProcessStateDto(
                dispatcher: 'queue',
                masterCommandName: $masterCommandName,
                masterPid: 111,
                childCommandName: 'artisan queue:work',
                childProcessPids: [222],
            )
        );

        $dispatcher = $this->createMock(Dispatcher::class);

        $dispatcher->expects(self::once())->method('start')
            ->willThrowException(new RuntimeException('fail'));

        $dispatcher->expects(self::once())->method('stop');

        $command = new class extends StartDispatcherCommand {
            public function argument($key = null): string
            {
                return 'queue';
            }
        };

        $this->prepareCommand($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fail');

        try {
            $command->handle($dispatcher, new DispatcherConfig());
        } finally {
            $state->purge();
        }
    }

    private function prepareCommand(Command $command): void
    {
        $command->setLaravel($this->getApp());

        $output = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
    }
}
