<?php

namespace SLoggerLaravel;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\Queue;
use SLoggerLaravel\Configs\DispatcherConfig;
use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Configs\DispatcherTransporterConfig;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Dispatcher\Items\DispatcherFactory;
use SLoggerLaravel\Dispatcher\Items\Queue\ApiClients\ApiClientFactory;
use SLoggerLaravel\Dispatcher\Items\Queue\ApiClients\ApiClientInterface;
use SLoggerLaravel\Dispatcher\Items\TraceDispatcherInterface;
use SLoggerLaravel\Dispatcher\Items\Transporter\Clients\TransporterClient;
use SLoggerLaravel\Dispatcher\Items\Transporter\Clients\TransporterClientInterface;
use SLoggerLaravel\Dispatcher\Items\Transporter\Commands\LoadTransporterCommand;
use SLoggerLaravel\Dispatcher\Items\Transporter\Commands\StartTransporterCommand;
use SLoggerLaravel\Dispatcher\Items\Transporter\Commands\StatTransporterCommand;
use SLoggerLaravel\Dispatcher\Items\Transporter\Commands\StopTransporterCommand;
use SLoggerLaravel\Dispatcher\Items\Transporter\TransporterLoader;
use SLoggerLaravel\Dispatcher\StartDispatcherCommand;
use SLoggerLaravel\Dispatcher\StopDispatcherCommand;
use SLoggerLaravel\Helpers\TraceDataComplementer;
use SLoggerLaravel\Middleware\HttpMiddleware;
use SLoggerLaravel\Profiling\AbstractProfiling;
use SLoggerLaravel\Profiling\XHProfProfiler;
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Watchers\AbstractWatcher;

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

        $this->app->singleton(TraceDataComplementer::class);
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

        $this->app->singleton(
            TransporterClientInterface::class,
            static function (Application $app) {
                return new TransporterClient(
                    apiToken: $app->make(GeneralConfig::class)->getToken(),
                    connectionResolver: static function (): QueueContract {
                        return Queue::connection(app(DispatcherTransporterConfig::class)->getQueueConnection());
                    },
                    queueName: $app->make(DispatcherTransporterConfig::class)->getQueueName()
                );
            }
        );

        $this->app->singleton(
            TraceDispatcherInterface::class,
            static function (Application $app) {
                return $app->make(DispatcherFactory::class)->create(
                    $app->make(DispatcherConfig::class)->getDefault()
                );
            }
        );

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

        $this->app->terminating(
            static function (TraceDispatcherInterface $dispatcher) {
                $dispatcher->terminate();
            }
        );
    }

    private function registerListeners(): void
    {
        $events = $this->app['events'];

        foreach ($this->app['config']['slogger.listeners'] ?? [] as $eventClass => $listenerClasses) {
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

        /** @var array{enabled: bool, class: class-string<AbstractWatcher>}[] $watcherConfigs */
        $watcherConfigs = $this->app['config']['slogger.watchers'] ?? [];

        foreach ($watcherConfigs as $watcherConfig) {
            if (!$watcherConfig['enabled']) {
                continue;
            }

            $watcherClass = $watcherConfig['class'];

            $state->addEnabledWatcher($watcherClass);

            /** @var AbstractWatcher $watcher */
            $watcher = $this->app->make($watcherClass);

            $watcher->register();
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
            LoadTransporterCommand::class,
            StartTransporterCommand::class,
            StopTransporterCommand::class,
            StatTransporterCommand::class,
        ]);

        $this->app->singleton(
            TransporterLoader::class,
            static fn() => new TransporterLoader(
                path: base_path('strans')
            )
        );
    }
}
