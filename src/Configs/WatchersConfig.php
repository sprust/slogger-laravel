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
        return config('slogger.watchers_customizing.requests.header_parent_trace_id_key');
    }

    /**
     * @return string[]
     */
    public function requestsExceptedPaths(): array
    {
        return config('slogger.watchers_customizing.requests.excepted_paths') ?? [];
    }

    /**
     * @return string[]
     */
    public function requestsInputFullHiding(): array
    {
        return config('slogger.watchers_customizing.requests.input.full_hiding') ?? [];
    }

    /**
     * @return array<string, string[]>
     */
    public function requestsInputMaskHeadersMasking(): array
    {
        return config('slogger.watchers_customizing.requests.input.headers_masking') ?? [];
    }

    /**
     * @return array<string, string[]>
     */
    public function requestsInputParametersMasking(): array
    {
        return config('slogger.watchers_customizing.requests.input.parameters_masking') ?? [];
    }

    /**
     * @return string[]
     */
    public function requestsOutputFullHiding(): array
    {
        return config('slogger.watchers_customizing.requests.output.full_hiding') ?? [];
    }

    /**
     * @return array<string, string[]>
     */
    public function requestsOutputHeadersMasking(): array
    {
        return config('slogger.watchers_customizing.requests.output.headers_masking') ?? [];
    }

    /**
     * @return array<string, string[]>
     */
    public function requestsOutputFieldsMasking(): array
    {
        return config('slogger.watchers_customizing.requests.output.fields_masking') ?? [];
    }

    /**
     * @return string[]
     */
    public function commandsExcepted(): array
    {
        return config('slogger.watchers_customizing.commands.excepted') ?? [];
    }

    /**
     * @return class-string[]
     */
    public function jobsExcepted(): array
    {
        return config('slogger.watchers_customizing.jobs.excepted') ?? [];
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
