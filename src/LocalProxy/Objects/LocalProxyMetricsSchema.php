<?php

namespace SLoggerLaravel\LocalProxy\Objects;

use InvalidArgumentException;

/**
 * Strict validation of the Go wire schema: every field the collector writes is
 * checked for presence and type, so a hand-written or outdated file is
 * rejected instead of being reported as zero metrics.
 */
final class LocalProxyMetricsSchema
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function section(array $data, string $key, string $path): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            throw new InvalidArgumentException("metrics snapshot: $path$key must be an object");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function int(array $data, string $key, string $path): int
    {
        $value = $data[$key] ?? null;

        if (!is_int($value)) {
            throw new InvalidArgumentException("metrics snapshot: $path$key must be an integer");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function nonNegativeInt(array $data, string $key, string $path): int
    {
        $value = self::int($data, $key, $path);

        if ($value < 0) {
            throw new InvalidArgumentException("metrics snapshot: $path$key must not be negative");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function bool(array $data, string $key, string $path): bool
    {
        $value = $data[$key] ?? null;

        if (!is_bool($value)) {
            throw new InvalidArgumentException("metrics snapshot: $path$key must be a boolean");
        }

        return $value;
    }

    /**
     * JSON 1e309 decodes to INF, which json_encode cannot serialize back.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function nonNegativeFiniteFloat(array $data, string $key, string $path): float
    {
        $value = $data[$key] ?? null;

        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException("metrics snapshot: $path$key must be a number");
        }

        $value = (float) $value;

        if (!is_finite($value) || $value < 0) {
            throw new InvalidArgumentException(
                "metrics snapshot: $path$key must be a finite non-negative number"
            );
        }

        return $value;
    }

    /**
     * An omitempty field: absent means null, present means the type must match.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function optionalInt(array $data, string $key, string $path): ?int
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        return self::int($data, $key, $path);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function optionalString(array $data, string $key, string $path): ?string
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];

        if (!is_string($value)) {
            throw new InvalidArgumentException("metrics snapshot: $path$key must be a string");
        }

        return $value;
    }
}
