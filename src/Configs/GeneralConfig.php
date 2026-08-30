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

    /**
     * Falls back to the process-wide store rather than trusting the published config
     * to carry the key: an application that published `config/slogger.php` before this
     * existed has no `context` in it, and the answer for it is what it always did.
     */
    public function getContextName(): string
    {
        $context = config('slogger.context');

        return is_string($context) && $context !== ''
            ? $context
            : 'array';
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
