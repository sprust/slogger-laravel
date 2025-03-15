<?php

namespace SLoggerLaravel\Configs;

class GeneralConfig
{
    private bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) config('slogger.enabled');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getToken(): string
    {
        return (string) config('slogger.token');
    }

    public function getLogChannel(): ?string
    {
        return config('slogger.log_channel');
    }
}
