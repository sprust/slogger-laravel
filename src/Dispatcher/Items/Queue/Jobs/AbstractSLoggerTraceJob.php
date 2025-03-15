<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\Items\Queue\ApiClients\ApiClientInterface;
use SLoggerLaravel\Processor;
use Throwable;

abstract class AbstractSLoggerTraceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    abstract protected function onHandle(ApiClientInterface $apiClient): void;

    public int $tries = 120;

    public int $backoff = 1;

    public function __construct()
    {
        $config = app(DispatcherQueueConfig::class);

        $this->onConnection($config->getConnection())
            ->onQueue($config->getName());
    }

    /**
     * @throws Throwable
     */
    public function handle(Processor $processor, ApiClientInterface $apiClient, GeneralConfig $config): void
    {
        try {
            $processor->handleWithoutTracing(
                fn() => $this->onHandle($apiClient)
            );
        } catch (Throwable $exception) {
            if ($this->job->attempts() < $this->tries) {
                $this->job->release($this->backoff);
            } else {
                $this->job->delete();

                Log::channel($config->getLogChannel())
                    ->error($exception->getMessage(), [
                        'code'  => $exception->getCode(),
                        'file'  => $exception->getFile(),
                        'line'  => $exception->getLine(),
                        'trace' => $exception->getTraceAsString(),
                    ]);
            }
        }
    }
}
