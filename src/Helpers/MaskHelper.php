<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Support\Str;

class MaskHelper
{
    /**
     * A fully masked string. Fixed width on purpose: the length of a secret is
     * itself worth hiding.
     */
    public const FULL_MASK = '********';

    /**
     * Nothing matched.
     */
    private const MODE_NONE = 0;

    /**
     * Enough is left to tell two values apart; the value itself is not recoverable.
     */
    private const MODE_PARTIAL = 1;

    /**
     * Nothing of the value survives.
     */
    private const MODE_FULL = 2;

    /**
     * Characters kept at each end by a partial mask, and the shortest string that
     * keeps any: below it a partial mask is a full one.
     */
    private const PARTIAL_VISIBLE  = 2;
    private const PARTIAL_MIN_KEPT = 6;

    /**
     * Above this, a string that looks like JSON is left alone rather than decoded.
     */
    private const MAX_JSON_LENGTH = 1000000;

    /**
     * Keys whose value is a URL query string. Such a value is masked parameter by
     * parameter instead of as a whole, so the shape of the request stays readable.
     *
     * @var string[]
     */
    private const QUERY_STRING_KEYS = [
        'query_string',
    ];

    /**
     * Masks every value whose key contains one of the keys, case-insensitively.
     *
     * `$fullKeys` are masked whole, `$partialKeys` keep a couple of characters at
     * each end: a token is worthless the moment any of it leaks, while an address or a
     * phone number is mostly there to tell two records apart. A key matching both
     * lists is masked whole - the stricter list wins.
     *
     * The top level is left alone: watchers put their own fixed structure there
     * (`connection_name`, `request`, `changes`, ...) and the traced data starts one
     * level in. Matching therefore begins inside that structure, so a top-level key is
     * neither masked itself nor able to drag its whole subtree in by name.
     *
     * @param array<int|string, mixed> $data
     * @param array<mixed>             $fullKeys
     * @param array<mixed>             $partialKeys
     *
     * @return array<int|string, mixed>
     */
    public static function maskArrayByKeys(array $data, array $fullKeys, array $partialKeys = []): array
    {
        $needles        = self::prepareNeedles($fullKeys);
        $partialNeedles = self::prepareNeedles($partialKeys);

        if (!$needles && !$partialNeedles) {
            return $data;
        }

        return self::maskNode(
            data: $data,
            prefix: '',
            needles: $needles,
            partialNeedles: $partialNeedles,
            mode: self::MODE_NONE,
            depth: 1
        );
    }

    /**
     * Masks a value whole. Scalars keep their type, so a masked payload stays
     * shaped like the original one.
     */
    public static function maskValue(mixed $value): mixed
    {
        return self::mask($value, self::MODE_FULL);
    }

    /**
     * Masks a value but keeps a couple of characters at each end, so two different
     * values still look different. Never use it for a secret: what is left is enough
     * to correlate records, and for a short value it is enough to guess it.
     */
    public static function maskValuePartially(mixed $value): mixed
    {
        return self::mask($value, self::MODE_PARTIAL);
    }

    /**
     * Tolerates a hand-written list: this is a public entry point, and a stray
     * non-string in a configured key list must not take a whole trace batch down.
     *
     * @param array<mixed> $keys
     *
     * @return string[]
     */
    private static function prepareNeedles(array $keys): array
    {
        return array_values(
            array_filter(
                array_map(
                    static fn(mixed $key): string => is_string($key) ? Str::lower($key) : '',
                    $keys
                )
            )
        );
    }

    /**
     * Walks the data instead of flattening it: a key that itself contains a dot would
     * not survive an Arr::dot()/Arr::set() round trip, and third-party payloads do
     * contain them.
     *
     * @param array<int|string, mixed> $data
     * @param string[]                 $needles
     * @param string[]                 $partialNeedles
     * @param int                      $mode           the mode an ancestor key already imposed
     *
     * @return array<int|string, mixed>
     */
    private static function maskNode(
        array $data,
        string $prefix,
        array $needles,
        array $partialNeedles,
        int $mode,
        int $depth
    ): array {
        $result = [];

        foreach ($data as $key => $value) {
            $segment = (string) $key;

            if ($depth === 1) {
                $path     = '';
                $thisMode = self::MODE_NONE;
            } else {
                $path     = $prefix === '' ? $segment : $prefix . '.' . $segment;
                $thisMode = max($mode, self::modeFor($path, $needles, $partialNeedles));
            }

            if (is_array($value)) {
                $result[$key] = $value === []
                    ? $value
                    : self::maskNode(
                        data: $value,
                        prefix: $path,
                        needles: $needles,
                        partialNeedles: $partialNeedles,
                        mode: $thisMode,
                        depth: $depth + 1
                    );

                continue;
            }

            $result[$key] = $thisMode === self::MODE_NONE
                ? self::maskStructuredString($value, $segment, $needles, $partialNeedles)
                : self::mask($value, $thisMode);
        }

        return $result;
    }

    /**
     * A string value can carry a structure of its own, whose keys the enclosing key
     * says nothing about. Two are looked into: a JSON document and a URL query
     * string.
     *
     * @param string[] $needles
     * @param string[] $partialNeedles
     */
    private static function maskStructuredString(
        mixed $value,
        string $key,
        array $needles,
        array $partialNeedles
    ): mixed {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (in_array(Str::lower($key), self::QUERY_STRING_KEYS, true)) {
            return self::maskQueryString($value, $needles, $partialNeedles);
        }

        return self::maskJsonString($value, $needles, $partialNeedles);
    }

    /**
     * Applications hand whole JSON documents over as strings - an Eloquent `array` cast
     * puts one straight into a model's changes - and the key carrying such a string
     * says nothing about what is inside it.
     *
     * A document in which something matched is re-encoded rather than patched, so its
     * exact bytes are not preserved: escaping is normalised, and a number too large or
     * too precise for a PHP float loses precision. A document in which nothing matched
     * is returned untouched.
     *
     * @param string[] $needles
     * @param string[] $partialNeedles
     */
    private static function maskJsonString(string $value, array $needles, array $partialNeedles): string
    {
        if (strlen($value) > self::MAX_JSON_LENGTH) {
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
            partialNeedles: $partialNeedles,
            mode: self::MODE_NONE,
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
     * Masks a query string parameter by parameter: `page=2&token=secret` keeps the
     * page and loses the token. Masking it as one value would hide which parameters
     * were sent at all, which is most of what a query string is worth in a trace.
     *
     * @param string[] $needles
     * @param string[] $partialNeedles
     */
    private static function maskQueryString(string $value, array $needles, array $partialNeedles): string
    {
        $parameters = [];

        parse_str($value, $parameters);

        if (!$parameters) {
            return $value;
        }

        // depth 2: every key here is the application's own
        $masked = self::maskNode(
            data: $parameters,
            prefix: '',
            needles: $needles,
            partialNeedles: $partialNeedles,
            mode: self::MODE_NONE,
            depth: 2
        );

        if ($masked === $parameters) {
            // nothing matched: parse_str is lossy (it mangles keys that are not valid
            // PHP variable names), so keep the original string
            return $value;
        }

        // http_build_query percent-encodes the mask itself, which turns a readable
        // `token=********` into `token=%2A%2A%2A%2A%2A%2A%2A%2A`; `*` is legal in a
        // query string, so put it back
        return str_replace('%2A', '*', http_build_query($masked));
    }

    /**
     * @param string[] $needles
     * @param string[] $partialNeedles
     */
    private static function modeFor(string $key, array $needles, array $partialNeedles): int
    {
        $lowerKey = Str::lower($key);

        if (self::containsAny($lowerKey, $needles)) {
            return self::MODE_FULL;
        }

        if (self::containsAny($lowerKey, $partialNeedles)) {
            return self::MODE_PARTIAL;
        }

        return self::MODE_NONE;
    }

    /**
     * @param string[] $needles
     */
    private static function containsAny(string $lowerKey, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($lowerKey, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function mask(mixed $value, int $mode): mixed
    {
        if (is_null($value)) {
            return null;
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
            return self::FULL_MASK;
        }

        if ($value === '') {
            // there is nothing to hide, and a mask here would claim there was
            return $value;
        }

        if ($mode === self::MODE_FULL) {
            return self::FULL_MASK;
        }

        $length = Str::length($value);

        if ($length < self::PARTIAL_MIN_KEPT) {
            // too short to give anything away safely
            return str_repeat('*', $length);
        }

        return Str::mask(
            string: $value,
            character: '*',
            index: self::PARTIAL_VISIBLE,
            length: $length - (self::PARTIAL_VISIBLE * 2)
        );
    }
}
