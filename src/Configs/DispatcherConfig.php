<?php

namespace SLoggerLaravel\Configs;

class DispatcherConfig
{
    public function getDefault(): string
    {
        return (string) config('slogger.dispatchers.default');
    }
}
