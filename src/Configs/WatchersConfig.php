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
     * @return string[]
     */
    public function getDataCompleterExcludedFileMasks(): array
    {
        return config('slogger.data_completer.excluded_file_masks') ?? [];
    }

    public function getDataCompleterMaxDepth(): int
    {
        return (int) (config('slogger.data_completer.max_depth') ?? 10);
    }
}
