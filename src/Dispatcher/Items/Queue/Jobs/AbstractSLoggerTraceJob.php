<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
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
        $this->onConnection(config('slogger.dispatchers.queue.connection'))
            ->onQueue(config('slogger.dispatchers.queue.name'));
    }

    /**
     * @throws Throwable
     */
    public function handle(Processor $processor, ApiClientInterface $apiClient): void
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

                Log::channel(config('slogger.log_channel'))
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
