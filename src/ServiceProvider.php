<?php

namespace SLoggerLaravel;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use SLoggerLaravel\Configs\DispatcherConfig;
use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Configs\MaskingConfig;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Dispatcher\ApiClients\ApiClientFactory;
use SLoggerLaravel\Dispatcher\ApiClients\ApiClientInterface;
use SLoggerLaravel\Dispatcher\Items\DispatcherFactory;
use SLoggerLaravel\Dispatcher\Items\Memory\MemoryDispatcher;
use SLoggerLaravel\Dispatcher\Items\Queue\QueueDispatcher;
use SLoggerLaravel\Dispatcher\Items\TraceDispatcherInterface;
use SLoggerLaravel\Dispatcher\StartDispatcherCommand;
use SLoggerLaravel\Dispatcher\StopDispatcherCommand;
use SLoggerLaravel\Helpers\TraceDataComplementer;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Middleware\HttpMiddleware;
use SLoggerLaravel\Profiling\AbstractProfiling;
use SLoggerLaravel\Profiling\XHProfProfiler;
use SLoggerLaravel\Traces\ProcessTraceScopeResolver;
use SLoggerLaravel\Traces\TraceScopeResolverInterface;
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Watchers\Children\ModelWatcher;
use Throwable;
use SLoggerLaravel\Watchers\WatcherInterface;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * @throws BindingResolutionException
     */
    public function register(): void
    {
        // a published config replaces this package's own, so without the merge an
        // application that published one before a key existed silently runs without it
        $this->mergeConfigFrom(__DIR__ . '/../config/slogger.php', 'slogger');

        $this->app->singleton(GeneralConfig::class);

        if (!$this->app->make(GeneralConfig::class)->isEnabled()) {
            return;
        }

        // every binding lives here, not in boot(): a provider that boots earlier and
        // resolves Processor, State or TraceIdContainer would get an auto-wired
        // duplicate outside the singleton, and end up with a second, disconnected
        // tracing state whose traces silently go nowhere
        // the scope resolver decides what "the current unit of work" means, and
        // everything the package keeps per unit follows it - see TraceScope. One
        // process is the answer for FPM, `queue:work` and artisan; an application on
        // a runtime that interleaves coroutines rebinds this with its own.
        $this->app->singleton(TraceScopeResolverInterface::class, ProcessTraceScopeResolver::class);

        $this->app->singleton(TraceDataComplementer::class);
        $this->app->singleton(MaskingConfig::class);
        $this->app->singleton(TraceDataMasker::class);
        $this->app->singleton(WatchersConfig::class);
        $this->app->singleton(State::class);
        $this->app->singleton(Processor::class);
        $this->app->singleton(TraceIdContainer::class);
        $this->app->singleton(HttpMiddleware::class);
        $this->app->singleton(AbstractProfiling::class, XHProfProfiler::class);

        $this->app->singleton(
            ApiClientInterface::class,
            static function (Application $app) {
                return $app->make(ApiClientFactory::class)->create(
                    $app->make(DispatcherQueueConfig::class)->getDefaultApiClient()
                );
            }
        );

        $this->app->singleton(QueueDispatcher::class);
        $this->app->singleton(MemoryDispatcher::class);

        $this->app->singleton(
            TraceDispatcherInterface::class,
            static function (Application $app) {
                return $app->make(DispatcherFactory::class)->create(
                    $app->make(DispatcherConfig::class)->getDefault()
                );
            }
        );
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->registerConsole();

        if (!$this->app->make(GeneralConfig::class)->isEnabled()) {
            return;
        }

        $this->warnAboutRetiredMaskingConfig();

        $this->registerListeners();
        $this->registerWatchers();

        $this->publishes(
            paths: [
                __DIR__ . '/../config/slogger.php' => config_path('slogger.php'),
            ],
            groups: [
                'slogger-laravel',
            ]
        );
    }

    /**
     * A config published before 1.3 still carries the per-watcher masking sections,
     * which are no longer read. Anything the application added to them - `*ssn*`,
     * `*iban*`, a model's own `masks` - silently stopped being masked the moment it
     * upgraded. Say so once, out loud: this is a security regression triggered by a
     * routine `composer update`, and a README is not where anyone will look for it.
     *
     * @throws BindingResolutionException
     */
    private function warnAboutRetiredMaskingConfig(): void
    {
        $retired = [
            'slogger.watchers_config.requests.input.headers_masking',
            'slogger.watchers_config.requests.input.parameters_masking',
            'slogger.watchers_config.requests.output.headers_masking',
            'slogger.watchers_config.requests.output.fields_masking',
        ];

        $found = array_values(
            array_filter($retired, static fn(string $key): bool => !is_null(config($key)))
        );

        /** @var array<array{class?: string, config?: array<string, mixed>}> $watcherConfigs */
        $watcherConfigs = $this->app->make(Repository::class)['slogger.watchers'] ?? [];

        foreach ($watcherConfigs as $watcherConfig) {
            if (($watcherConfig['class'] ?? null) === ModelWatcher::class
                && isset($watcherConfig['config']['masks'])
            ) {
                $found[] = ModelWatcher::class . ' config.masks';
            }
        }

        if (!$found) {
            return;
        }

        try {
            Log::channel($this->app->make(GeneralConfig::class)->getLogChannel())
                ->warning(
                    sprintf(
                        'slogger: these config sections are no longer read and mask nothing: %s. '
                        . 'Move the keys you added there into masking.full_keys or masking.partial_keys.',
                        implode(', ', $found)
                    )
                );
        } catch (Throwable) {
            // a broken log channel must not stop the application from booting
        }
    }

    /**
     * @throws BindingResolutionException
     */
    private function registerListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $listeners = $this->app->make(Repository::class)['slogger.listeners'] ?? [];

        foreach ($listeners as $eventClass => $listenerClasses) {
            foreach ($listenerClasses as $listenerClass) {
                $events->listen($eventClass, $listenerClass);
            }
        }
    }

    /**
     * @throws BindingResolutionException
     */
    private function registerWatchers(): void
    {
        $processor = $this->app->make(Processor::class);

        /** @var array{enabled: bool, class: class-string<WatcherInterface>, config?: array<string, mixed>}[] $watcherConfigs */
        $watcherConfigs = $this->app->make(Repository::class)['slogger.watchers'] ?? [];

        foreach ($watcherConfigs as $watcherConfig) {
            if (!$watcherConfig['enabled']) {
                continue;
            }

            $processor->registerWatcher(
                watcherClass: $watcherConfig['class'],
                config: $watcherConfig['config'] ?? null,
            );
        }
    }

    private function registerConsole(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            StartDispatcherCommand::class,
            StopDispatcherCommand::class,
        ]);
    }
}
