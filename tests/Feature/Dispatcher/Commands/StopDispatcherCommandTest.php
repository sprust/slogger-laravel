<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Commands;

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Container\BindingResolutionException;
use SLoggerLaravel\Dispatcher\Dispatcher;
use SLoggerLaravel\Dispatcher\StartDispatcherCommand;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessState;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessStateDto;
use SLoggerLaravel\Dispatcher\StopDispatcherCommand;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class StopDispatcherCommandTest extends BaseTestCase
{
    /**
     * @throws BindingResolutionException
     */
    public function testHandleWarnsWhenNoState(): void
    {
        $command = $this->getApp()->make(StopDispatcherCommand::class);

        $output = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects(self::never())->method('stop');

        $command->handle($dispatcher);

        self::assertStringContainsString('Dispatcher not started', $output->fetch());
    }

    /**
     * @throws BindingResolutionException
     */
    public function testHandleStopsWhenStateExists(): void
    {
        $masterCommandName = $this->getApp()->make(StartDispatcherCommand::class)
            ->getName();

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

        $command = $this->getApp()->make(StopDispatcherCommand::class);

        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects(self::once())->method('stop');

        $command->handle($dispatcher);

        $state->purge();
    }
}
