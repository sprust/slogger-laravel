<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Artisan;
use SLoggerLaravel\Dispatcher\Items\Memory\MemoryDispatcher;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\WatcherInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class BaseWatcherTestCase extends BaseTestCase
{
    protected Processor $processor;
    protected MemoryDispatcher $dispatcher;

    /**
     * @throws BindingResolutionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        $this->processor = $this->app->make(Processor::class);
        $this->dispatcher = $this->app->make(MemoryDispatcher::class);

        $this->dispatcher->flush();
    }

    /**
     * @param class-string<WatcherInterface> $watcherClass
     * @param array<string, mixed>|null $config
     */
    protected function registerWatcher(string $watcherClass, ?array $config): void
    {
        $this->processor->registerWatcher($watcherClass, $config);
    }

    protected function artisanCall(string $command): int
    {
        $input = new ArrayInput(['command' => $command]);
        $output = new BufferedOutput();

        event(
            new CommandStarting(
                command: $command,
                input: $input,
                output: $output,
            )
        );

        $exitCode = Artisan::call(
            command: $command,
            outputBuffer: $output
        );

        event(
            new CommandFinished(
                command: $command,
                input: $input,
                output: $output,
                exitCode: $exitCode,
            )
        );

        return $exitCode;
    }
}
