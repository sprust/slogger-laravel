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

    private const DROP_LOG_INTERVAL_SECONDS = 60;

    private static int $lastDropLogAt  = 0;
    private static int $droppedTraces  = 0;
    private static int $droppedBatches = 0;

    // tries = count(backoff) + 1: every backoff pause is used before the batch is dropped
    public int $tries = 5;

    /**
     * The int arm is required, not decorative: some queue drivers assign a computed
     * int back to $backoff when releasing a job (e.g. laravel-queue-rabbitmq). A
     * plain `array` type makes that assignment throw a TypeError and breaks the
     * retry/drop machinery. Laravel serializes the array into the job payload via
     * getJobBackoff(), so the [1,10,30,60] schedule is still honored on standard drivers.
     *
     * @var int|list<int>
     */
    public int|array $backoff = [5, 10, 30, 60];

    protected readonly string $tracesJson;
    protected readonly int $tracesCount;

    public function __construct(TracesObject $traces)
    {
        $this->tracesCount = $traces->count();

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
            if ($this->job && $this->job->attempts() >= $this->tries) {
                // drop the batch: telemetry must not clutter the failed jobs storage
                $this->job->delete();

                $this->logDrop($config, $exception);

                return;
            }

            // let Laravel release the job with the configured backoff
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        // safety net for worker-side failures (timeouts, crashes between attempts)
        $this->logDrop(app(GeneralConfig::class), $exception);
    }

    public static function resetDropStats(): void
    {
        self::$lastDropLogAt  = 0;
        self::$droppedTraces  = 0;
        self::$droppedBatches = 0;
    }

    private function logDrop(GeneralConfig $config, ?Throwable $exception): void
    {
        self::$droppedBatches++;
        self::$droppedTraces += $this->tracesCount;

        $now = time();

        if (($now - self::$lastDropLogAt) < self::DROP_LOG_INTERVAL_SECONDS) {
            return;
        }

        self::$lastDropLogAt = $now;

        $droppedTraces  = self::$droppedTraces;
        $droppedBatches = self::$droppedBatches;

        self::$droppedTraces  = 0;
        self::$droppedBatches = 0;

        try {
            Log::channel($config->getLogChannel())
                ->warning(
                    sprintf(
                        'slogger: dropped %d traces in %d batches after %d tries: %s',
                        $droppedTraces,
                        $droppedBatches,
                        $this->tries,
                        $exception?->getMessage() ?? 'unknown error'
                    )
                );
        } catch (Throwable) {
            // no action
        }
    }
}
