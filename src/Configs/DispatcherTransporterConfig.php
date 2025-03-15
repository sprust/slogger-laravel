<?php

namespace SLoggerLaravel\Configs;

class DispatcherTransporterConfig
{
    public function getQueueConnection(): string
    {
        return (string) config('slogger.dispatchers.transporter.queue.connection');
    }

    public function getQueueName(): string
    {
        return (string) config('slogger.dispatchers.transporter.queue.name');
    }

    /**
     * @return array<string, mixed>
     */
    public function getEnv(): array
    {
        return config('slogger.dispatchers.transporter.env') ?? [];
    }
}
