<?php

namespace SLoggerLaravel\Configs;

readonly class MaskingConfig
{
    /**
     * Case-insensitive substrings of a trace data key whose value is masked whole. An
     * empty list turns full masking off; only an explicit empty list does, a missing
     * one falls back to the shipped defaults.
     *
     * @return string[]
     */
    public function getFullKeys(): array
    {
        return $this->readKeys('full_keys');
    }

    /**
     * The same, for keys whose value keeps a couple of characters at each end.
     *
     * @return string[]
     */
    public function getPartialKeys(): array
    {
        return $this->readKeys('partial_keys');
    }

    /**
     * A misconfigured list must not take the whole telemetry down with it: masking
     * runs inside the dispatcher job, where a TypeError costs the batch and every
     * retry of it.
     *
     * @return string[]
     */
    private function readKeys(string $name): array
    {
        $configured = config("slogger.masking.$name");

        if (is_null($configured)) {
            $configured = self::shippedDefaults()[$name] ?? [];
        }

        return array_values(
            array_filter(
                (array) $configured,
                static fn(mixed $key): bool => is_string($key) && $key !== ''
            )
        );
    }

    /**
     * The package's own config file, read straight from disk.
     *
     * `ServiceProvider::register()` merges it into the application's configuration,
     * but `mergeConfigFrom` is a no-op while that configuration is cached - so an
     * application upgrading without rebuilding its config cache would otherwise mask
     * nothing at all. Reading the file keeps the defaults in one place: the file a
     * user publishes and edits.
     *
     * @return array<string, mixed>
     */
    private static function shippedDefaults(): array
    {
        /** @var array<string, mixed>|null $masking */
        static $masking = null;

        if (is_null($masking)) {
            /** @var array<string, mixed> $config */
            $config = require __DIR__ . '/../../config/slogger.php';

            /** @var array<string, mixed> $masking */
            $masking = $config['masking'] ?? [];
        }

        return $masking;
    }
}
