<?php

namespace SLoggerLaravel\Configs;

readonly class MaskingConfig
{
    /**
     * Case-insensitive substrings of a trace data key. An empty list turns masking off.
     *
     * @return string[]
     */
    public function getKeys(): array
    {
        return config('slogger.masking.keys') ?? [];
    }

    /**
     * Wildcard masks matched against the whole dotted key.
     *
     * @return string[]
     */
    public function getExceptedKeys(): array
    {
        return config('slogger.masking.excepted_keys') ?? [];
    }
}
