<?php

namespace SLoggerLaravel\Configs;

use RuntimeException;

class DispatcherQueueConfig
{
    public function getConnection(): string
    {
        $connection = config('slogger.dispatchers.queue.connection');

        if (!is_string($connection) || $connection === '') {
            throw new RuntimeException(
                'SLogger: queue dispatcher connection is not configured.'
                . ' Set the SLOGGER_DISPATCHER_QUEUE_CONNECTION env explicitly:'
                . ' telemetry must not silently share the application queue connection.'
            );
        }

        return $connection;
    }

    public function getName(): string
    {
        return (string) config('slogger.dispatchers.queue.name');
    }

    public function getWorkersNum(): int
    {
        return (int) config('slogger.dispatchers.queue.workers_num');
    }

    public function getDefaultApiClient(): string
    {
        return (string) config('slogger.dispatchers.queue.api_clients.default');
    }

    public function getSocketClientUrl(): string
    {
        return (string) config('slogger.dispatchers.queue.api_clients.socket.url');
    }
}
