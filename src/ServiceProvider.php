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
use SLoggerLaravel\Traces\ProcessTraceScopeResolver;
use SLoggerLaravel\Traces\TraceScopeResolverInterface;
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

        // every binding lives here, not in boot(): a provider that boots earlier and
        // resolves Processor or State would get an auto-wired duplicate outside the
        // singleton, and end up with a second, disconnected tracing state whose
        // traces silently go nowhere.
        //
        // the scope resolver decides what "the current unit of work" means, and
        // everything the package keeps per unit follows it - see TraceScope. One
        // process is the answer for FPM, `queue:work` and artisan; an application on
        // a runtime that interleaves coroutines binds its own.
        //
        // singletonIf: an application binding this in its own provider registers
        // after this one and wins either way, but one bound earlier - in
        // `bootstrap/app.php`, or by whatever bootstraps the runtime - would be
        // overwritten by a plain singleton()
        $this->app->singletonIf(TraceScopeResolverInterface::class, ProcessTraceScopeResolver::class);

        $this->app->singleton(TraceDataComplementer::class);
        $this->app->singleton(MaskingConfig::class);
        $this->app->singleton(TraceDataMasker::class);
        $this->app->singleton(WatchersConfig::class);
        $this->app->singleton(State::class);
        $this->app->singleton(Processor::class);
        $this->app->singleton(HttpMiddleware::class);

        // the Guzzle handler factory resolves this watcher too, and a fresh instance
        // there means the request map it fills, the sweep callback registered on it
        // and the random header key it generates all belong to different objects
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

            // the one instance, for good: a watcher keeps what it has open on itself,
            // and a second instance resolved later would be a second, disconnected
            // set of open traces whose entries nothing ever closes
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
    }
}
