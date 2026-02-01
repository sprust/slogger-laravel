<?php

namespace SLoggerLaravel;

use SLoggerLaravel\Watchers\WatcherInterface;

class State
{
    /**
     * @var class-string<WatcherInterface>[]
     */
    private array $enabledWatcherClasses = [];

    /**
     * @param class-string<WatcherInterface> $watcherClass
     */
    public function addEnabledWatcher(string $watcherClass): void
    {
        $this->enabledWatcherClasses[] = $watcherClass;
    }

    /**
     * @param class-string<WatcherInterface> $watcherClass
     */
    public function isWatcherEnabled(string $watcherClass): bool
    {
        return in_array($watcherClass, $this->enabledWatcherClasses);
    }
}
