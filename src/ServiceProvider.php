<?php

namespace SLoggerLaravel;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Events\Dispatcher;
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
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Watchers\Children\HttpClientWatcher;
use SLoggerLaravel\Watchers\WatcherInterface;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * @throws BindingResolutionException
     */
    public function register(): void
    {
        $this->app->singleton(GeneralConfig::class);

        if (!$this->app->make(GeneralConfig::class)->isEnabled()) {
            return;
        }

        // here, not in boot(): a provider booting earlier would resolve an auto-wired
        // Processor outside the singleton and trace into nowhere
        $this->app->singleton(TraceDataComplementer::class);
        $this->app->singleton(MaskingConfig::class);
        $this->app->singleton(TraceDataMasker::class);
        $this->app->singleton(WatchersConfig::class);
        $this->app->singleton(LocalStorage::class);
        $this->app->singleton(State::class);
        $this->app->singleton(Processor::class);
        $this->app->singleton(TraceIdContainer::class);
        $this->app->singleton(HttpMiddleware::class);

        // the Guzzle handler factory resolves this too, and a fresh instance there
        // would have its own request map, sweep callback and header key
        $this->app->singleton(HttpClientWatcher::class);
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

        $this->registerListeners();
        $this->registerWatchers();
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
        $state = $this->app->make(State::class);

        /** @var array{enabled: bool, class: class-string<WatcherInterface>, config?: array<string, mixed>}[] $watcherConfigs */
        $watcherConfigs = $this->app->make(Repository::class)['slogger.watchers'] ?? [];

        foreach ($watcherConfigs as $watcherConfig) {
            if (!$watcherConfig['enabled']) {
                continue;
            }

            $watcherClass = $watcherConfig['class'];

            /** @var WatcherInterface $watcher */
            $watcher = $this->app->make($watcherClass);

            // the one instance, for good: a watcher keeps what it has open on itself
            $this->app->instance($watcherClass, $watcher);

            $watcher->register($watcherConfig['config'] ?? null);

            $state->addEnabledWatcher($watcherClass);
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

        $this->publishes(
            paths: [
                __DIR__ . '/../config/slogger.php' => config_path('slogger.php'),
            ],
            groups: [
                'slogger-laravel',
            ]
        );
    }
}
