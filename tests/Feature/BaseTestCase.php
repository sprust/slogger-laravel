<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature;

use App\Providers\WorkbenchServiceProvider;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use SLoggerLaravel\Dispatcher\Items\Memory\MemoryDispatcher;
use SLoggerLaravel\Processor;
use SLoggerLaravel\ServiceProvider;
use SLoggerLaravel\Watchers\WatcherInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class BaseTestCase extends TestCase
{
    use WithWorkbench;

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

    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
            WorkbenchServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
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
