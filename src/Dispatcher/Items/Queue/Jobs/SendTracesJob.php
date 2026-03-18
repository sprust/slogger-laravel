<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\ApiClients\ApiClientInterface;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Processor;
use Throwable;

class SendTracesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries   = 120;
    public int $backoff = 1;

    protected readonly string $tracesJson;

    public function __construct(TracesObject $traces)
    {
        try {
            $this->tracesJson = $traces->toJson();
        } catch (JsonException $exception) {
            throw new RuntimeException(
                message: 'Failed to serialize traces: ' . $exception->getMessage(),
                previous: $exception
            );
        }

        $config = app(DispatcherQueueConfig::class);

        $this->onConnection($config->getConnection())
            ->onQueue($config->getName());
    }

    /**
     * @throws Throwable
     */
    public function handle(
        Processor $processor,
        ApiClientInterface $apiClient,
        GeneralConfig $config
    ): void {
        try {
            $traces = TracesObject::fromJson($this->tracesJson);

            $processor->handleWithoutTracing(
                fn() => $apiClient->sendTraces($traces)
            );
        } catch (Throwable $exception) {
            if (!$this->job) {
                throw $exception;
            }

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
