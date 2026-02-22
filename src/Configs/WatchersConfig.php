<?php

namespace SLoggerLaravel\Configs;

readonly class WatchersConfig
{
    public function profilingEnabled(): bool
    {
        return (bool) config('slogger.profiling.enabled');
    }

    public function requestsHeaderParentTraceIdKey(): ?string
    {
        return config('slogger.http_parent_trace_id_header_key');
    }

    /**
     * @return array<string, string[]>
     */
    public function modelsMasks(): array
    {
        return config('slogger.watchers_customizing.models.masks') ?? [];
    }

    /**
     * @return string[]
     */
    public function getDataCompleterExcludedFileMasks(): array
    {
        return config('slogger.data_completer.excluded_file_masks') ?? [];
    }

    /**
     * @return class-string<object>[]
     */
    public function eventsIgnoreEvents(): array
    {
        return config('slogger.watchers_customizing.events.ignore_events');
    }

    /**
     * @return class-string<object>[]
     */
    public function eventsSerializeEvents(): array
    {
        return config('slogger.watchers_customizing.events.serialize_events');
    }

    /**
     * @return class-string<object>[]
     */
    public function eventsCanBeOrphan(): array
    {
        return config('slogger.watchers_customizing.events.can_be_orphan');
    }
}
