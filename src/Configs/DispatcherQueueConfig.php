<?php

namespace SLoggerLaravel\Configs;

class DispatcherQueueConfig
{
    public function getConnection(): string
    {
        return (string) config('slogger.dispatchers.queue.connection');
    }

    public function getName(): string
    {
        return (string) config('slogger.dispatchers.queue.name');
    }

    public function getWorkersNum(): int
    {
        return (int) config('slogger.dispatchers.queue.workers_num');
    }

    public function getWorkerTries(): int
    {
        return (int) config('slogger.dispatchers.queue.worker_tries');
    }

    public function getWorkerBackoff(): int
    {
        return (int) config('slogger.dispatchers.queue.worker_backoff');
    }

    public function getDefaultApiClient(): string
    {
        return (string) config('slogger.dispatchers.queue.api_clients.default');
    }

    public function getHttpClientUrl(): string
    {
        return (string) config('slogger.dispatchers.queue.api_clients.http.url');
    }

    public function getSocketClientUrl(): string
    {
        return (string) config('slogger.dispatchers.queue.api_clients.socket.url');
    }
}
