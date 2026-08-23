<?php

namespace SLoggerLaravel\Configs;

class MaskingConfig
{
    /**
     * Case-insensitive masks matched against a trace data key - the whole of it and
     * each of its word components, never as a substring - whose value is masked
     * whole. An empty list turns full masking off; only an explicit empty list does,
     * a missing one falls back to the shipped defaults.
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
            return [];
        }

        return array_values(
            array_filter(
                (array) $configured,
                static fn(mixed $key): bool => is_string($key) && $key !== ''
            )
        );
    }
}
