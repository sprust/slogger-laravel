<?php

namespace SLoggerLaravel\Configs;

class MaskingConfig
{
    /**
     * The package's own config, read once per instance rather than per call. The
     * class is bound as a singleton, so that is once per process.
     *
     * @var array<string, mixed>|null
     */
    private ?array $shippedMasking = null;

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
     * Regular expressions matched against a value rather than a key. What they match
     * is masked in place, partially.
     *
     * @return string[]
     */
    public function getValuePatterns(): array
    {
        return $this->readKeys('value_patterns');
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
            $configured = $this->shippedDefaults()[$name] ?? [];
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
     * A published config replaces this one rather than extending it, so an
     * application that published before a list existed has no value for it at all -
     * and would then mask nothing. Reading the file keeps the defaults in one place:
     * the same file a user publishes and edits.
     *
     * @return array<string, mixed>
     */
    private function shippedDefaults(): array
    {
        if (!is_null($this->shippedMasking)) {
            return $this->shippedMasking;
        }

        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../config/slogger.php';

        /** @var array<string, mixed> $masking */
        $masking = $config['masking'] ?? [];

        return $this->shippedMasking = $masking;
    }
}
