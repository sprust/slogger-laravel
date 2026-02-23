<?php

namespace SLoggerLaravel\Watchers;

interface WatcherInterface
{
    /**
     * @param array<string, mixed>|null $config
     */
    public function register(?array $config): void;
}
