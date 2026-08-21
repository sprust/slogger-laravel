<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Command;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use SLoggerLaravel\Events\WatcherErrorEvent;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Watchers\Parents\CommandWatcher;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ExceptedCommandWatcherTest extends BaseWatcherTestCase
{
    public function test(): void
    {
        $this->registerWatcher(
            watcherClass: CommandWatcher::class,
            config: [
                'excepted' => [
                    'slogger:test-excepted',
                ],
            ]
        );

        $exitCode = $this->artisanCall('slogger:test-success');
        self::assertSame(0, $exitCode);

        $exitCode = $this->artisanCall('slogger:test-excepted');
        self::assertSame(0, $exitCode);

        $creating = $this->dispatcher->findCreating(
            type: 'command',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );
    }

    public function testExceptedCommandNestedInATracedOne(): void
    {
        $this->registerWatcher(
            watcherClass: CommandWatcher::class,
            config: [
                'excepted' => [
                    'slogger:test-excepted',
                ],
            ]
        );

        $errors = [];

        Event::listen(
            WatcherErrorEvent::class,
            static function (WatcherErrorEvent $event) use (&$errors): void {
                $errors[] = $event->exception->getMessage();
            }
        );

        // a traced command runs an excepted one, the way `slogger:dispatcher:start`
        // runs `queue:work`
        $this->fireCommand('slogger:test-success', function (): void {
            $this->fireCommand('slogger:test-excepted', static fn() => null);
        });

        self::assertSame([], $errors);

        $creating = $this->dispatcher->findCreating(
            type: 'command',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);

        // the finish of the excepted command must not close the trace of the command
        // that is running it
        self::assertSame('slogger:test-success', $updating[0]->data['command'] ?? null);
    }

    /**
     * @param callable(): void $inside
     */
    private function fireCommand(string $command, callable $inside): void
    {
        $input  = new ArrayInput(['command' => $command]);
        $output = new BufferedOutput();

        event(new CommandStarting(command: $command, input: $input, output: $output));

        $inside();

        event(
            new CommandFinished(
                command: $command,
                input: $input,
                output: $output,
                exitCode: 0,
            )
        );
    }
}
