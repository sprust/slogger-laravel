<?php

namespace SLoggerLaravel\Configs;

readonly class MaskingConfig
{
    /**
     * The defaults live here, not only in the published config file: `mergeConfigFrom`
     * is a no-op while the configuration is cached, so an application that upgrades
     * without rebuilding its config cache would otherwise mask nothing at all.
     *
     * @var string[]
     */
    public const DEFAULT_KEYS = [
        'token',
        'pass',
        'auth',
        'email',
        'phone',
        '_name',
        'lastname',
        'firstname',
        'surname',
        'secret',
        'private',
        'apikey',
        'api_key',
        'api-key',
        'credential',
        'sign',
        'cookie',
    ];

    /**
     * @var string[]
     */
    public const DEFAULT_EXCEPTED_KEYS = [
        'connection_name',
    ];

    /**
     * Case-insensitive substrings of a trace data key. An empty list turns masking off;
     * only an explicit empty list does, a missing one falls back to the defaults.
     *
     * @return string[]
     */
    public function getKeys(): array
    {
        return config('slogger.masking.keys') ?? self::DEFAULT_KEYS;
    }

    /**
     * Wildcard masks matched against the whole dotted key.
     *
     * @return string[]
     */
    public function getExceptedKeys(): array
    {
        return config('slogger.masking.excepted_keys') ?? self::DEFAULT_EXCEPTED_KEYS;
    }
}
