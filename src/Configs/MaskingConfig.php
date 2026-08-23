<?php

namespace SLoggerLaravel\Configs;

class MaskingConfig
{
    /**
     * Case-insensitive masks matched against a trace data key - the whole of it and
     * each of its word components, never as a substring. An empty list turns full
     * masking off.
     *
     * @return string[]
     */
    public function getFullKeys(): array
    {
        return $this->readKeys('full_keys');
    }

    /**
     * The same, for values that keep a couple of characters at each end.
     *
     * @return string[]
     */
    public function getPartialKeys(): array
    {
        return $this->readKeys('partial_keys');
    }

    /**
     * Matched against a value rather than a key, and masked in place.
     *
     * @return string[]
     */
    public function getValuePatterns(): array
    {
        return $this->readKeys('value_patterns');
    }

    /**
     * A misconfigured list must not take the batch down: this runs inside the
     * dispatcher job.
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
