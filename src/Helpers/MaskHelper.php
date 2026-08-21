<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MaskHelper
{
    /**
     * Masks every value whose dotted key contains one of the keys, case-insensitively.
     *
     * @param array<int|string, mixed> $data
     * @param string[]                 $keys
     * @param string[]                 $exceptedKeyPatterns wildcard masks of whole dotted keys
     *
     * @return array<int|string, mixed>
     */
    public static function maskArrayByKeys(array $data, array $keys, array $exceptedKeyPatterns = []): array
    {
        $needles = array_values(
            array_filter(
                array_map(
                    static fn(string $key): string => Str::lower($key),
                    $keys
                )
            )
        );

        if (!$needles) {
            return $data;
        }

        $result = [];

        foreach (Arr::dot($data) as $key => $value) {
            if (self::keyContainsAny((string) $key, $needles, $exceptedKeyPatterns)) {
                $value = self::maskValue($value);
            }

            Arr::set($result, $key, $value);
        }

        return $result;
    }

    public static function maskValue(mixed $value): mixed
    {
        if (!$value) {
            return $value;
        }

        if (is_bool($value)) {
            return false;
        }

        if (is_int($value)) {
            return 0;
        }

        if (is_float($value)) {
            return 0.0;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            $value = '********';
        } elseif (strlen($value) === 1) {
            $value = '*';
        } else {
            $batchLength = (int) ceil(Str::length($value) / 3);

            $value = Str::mask(
                string: $value,
                character: '*',
                index: $batchLength,
                length: $batchLength
            );
        }

        return $value;
    }

    /**
     * @param string[] $needles
     * @param string[] $exceptedKeyPatterns
     */
    private static function keyContainsAny(string $key, array $needles, array $exceptedKeyPatterns): bool
    {
        if ($exceptedKeyPatterns && Str::is($exceptedKeyPatterns, $key)) {
            return false;
        }

        $lowerKey = Str::lower($key);

        foreach ($needles as $needle) {
            if (str_contains($lowerKey, $needle)) {
                return true;
            }
        }

        return false;
    }
}
