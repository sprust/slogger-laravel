<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Support\Str;

class MaskHelper
{
    /**
     * Above this, a string that looks like JSON is left alone rather than decoded.
     */
    private const MAX_JSON_LENGTH = 1000000;

    /**
     * Masks every value whose key contains one of the keys, case-insensitively.
     *
     * The top level is left alone: watchers put their own fixed structure there
     * (`connection_name`, `request`, `changes`, ...) and the traced data starts one
     * level in. Matching therefore begins inside that structure, so a top-level key is
     * neither masked itself nor able to drag its whole subtree in by name.
     *
     * @param array<int|string, mixed> $data
     * @param string[]                 $keys
     *
     * @return array<int|string, mixed>
     */
    public static function maskArrayByKeys(array $data, array $keys): array
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

        return self::maskNode(
            data: $data,
            prefix: '',
            needles: $needles,
            masked: false,
            depth: 1
        );
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
        } elseif (Str::length($value) === 1) {
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
     * Walks the data instead of flattening it: a key that itself contains a dot would
     * not survive an Arr::dot()/Arr::set() round trip, and third-party payloads do
     * contain them.
     *
     * @param array<int|string, mixed> $data
     * @param string[]                 $needles
     * @param bool                     $masked  whether an ancestor key already matched
     *
     * @return array<int|string, mixed>
     */
    private static function maskNode(
        array $data,
        string $prefix,
        array $needles,
        bool $masked,
        int $depth
    ): array {
        $result = [];

        foreach ($data as $key => $value) {
            if ($depth === 1) {
                $path     = '';
                $maskThis = false;
            } else {
                $path     = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                $maskThis = $masked || self::keyContainsAny($path, $needles);
            }

            if (is_array($value)) {
                $result[$key] = $value === []
                    ? $value
                    : self::maskNode(
                        data: $value,
                        prefix: $path,
                        needles: $needles,
                        masked: $maskThis,
                        depth: $depth + 1
                    );

                continue;
            }

            $result[$key] = $maskThis
                ? self::maskValue($value)
                : self::maskJsonString($value, $needles);
        }

        return $result;
    }

    /**
     * Applications hand whole JSON documents over as strings - an Eloquent `array` cast
     * puts one straight into a model's changes - and the key carrying such a string
     * says nothing about what is inside it.
     *
     * @param string[] $needles
     */
    private static function maskJsonString(mixed $value, array $needles): mixed
    {
        if (!is_string($value) || strlen($value) > self::MAX_JSON_LENGTH) {
            return $value;
        }

        $trimmed = ltrim($value);

        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            return $value;
        }

        // depth 2: the document is the application's own data all the way up, unlike
        // the trace data it sits in, whose top level belongs to the watcher
        $masked = self::maskNode(
            data: $decoded,
            prefix: '',
            needles: $needles,
            masked: false,
            depth: 2
        );

        if ($masked === $decoded) {
            // nothing matched: keep the original bytes rather than a re-encoded
            // approximation of them
            return $value;
        }

        $encoded = json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? $value : $encoded;
    }

    /**
     * @param string[] $needles
     */
    private static function keyContainsAny(string $key, array $needles): bool
    {
        $lowerKey = Str::lower($key);

        foreach ($needles as $needle) {
            if (str_contains($lowerKey, $needle)) {
                return true;
            }
        }

        return false;
    }
}
