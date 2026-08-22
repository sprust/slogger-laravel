<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Support\Str;

/**
 * @phpstan-type MaskingRules array{needles: string[], partialNeedles: string[], valuePatterns: string[]}
 */
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
     * Above this, a string is left alone rather than decoded or scanned.
     */
    private const MAX_STRING_LENGTH = 1000000;

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
     * Masks every value whose key contains one of the keys, case-insensitively, and
     * every occurrence of one of the value patterns wherever it appears.
     *
     * `$keys` are masked whole, `$partialKeys` keep a couple of characters at each
     * end: a token is worthless the moment any of it leaks, while an address or a
     * phone number is mostly there to tell two records apart. A key matching both
     * lists is masked whole - the stricter list wins.
     *
     * `$valuePatterns` match the value instead of the key, and are masked partially.
     * Some things identify a person by their own shape, wherever they turn up - an
     * address in a `notifiable` string, or in the middle of a log message - and no
     * key name points at those.
     *
     * The top level is left alone: watchers put their own fixed structure there
     * (`connection_name`, `request`, `changes`, ...) and the traced data starts one
     * level in. Matching therefore begins inside that structure, so a top-level key is
     * neither masked itself nor able to drag its whole subtree in by name. Value
     * patterns are not bound to a key, so they apply at every level.
     *
     * @param array<int|string, mixed> $data
     * @param array<mixed>             $fullKeys
     * @param array<mixed>             $partialKeys
     * @param array<mixed>             $valuePatterns
     *
     * @return array<int|string, mixed>
     */
    public static function maskArrayByKeys(
        array $data,
        array $fullKeys,
        array $partialKeys = [],
        array $valuePatterns = []
    ): array {
        $rules = [
            'needles'        => self::prepareNeedles($fullKeys),
            'partialNeedles' => self::prepareNeedles($partialKeys),
            'valuePatterns'  => self::preparePatterns($valuePatterns),
        ];

        if (!$rules['needles'] && !$rules['partialNeedles'] && !$rules['valuePatterns']) {
            return $data;
        }

        return self::maskNode(
            data: $data,
            prefix: '',
            rules: $rules,
            mode: self::MODE_NONE,
            depth: 1
        );
    }

    /**
     * Masks every occurrence of a value pattern in a standalone string.
     *
     * Tags and array keys are strings with no key naming them, so the key lists
     * cannot reach either. What identifies a person by its own shape still can be
     * found there.
     *
     * @param array<mixed> $valuePatterns
     */
    public static function maskString(string $value, array $valuePatterns): string
    {
        $patterns = self::preparePatterns($valuePatterns);

        if (!$patterns || $value === '' || strlen($value) > self::MAX_STRING_LENGTH) {
            return $value;
        }

        return self::maskByValuePatterns($value, $patterns);
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
     * Drops anything that is not a usable regular expression. A typo in a configured
     * pattern would otherwise raise a warning for every string in every trace, from
     * inside the dispatcher job.
     *
     * @param array<mixed> $patterns
     *
     * @return string[]
     */
    private static function preparePatterns(array $patterns): array
    {
        $prepared = [];

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            if (@preg_match($pattern, '') === false) {
                continue;
            }

            $prepared[] = $pattern;
        }

        return $prepared;
    }

    /**
     * Walks the data instead of flattening it: a key that itself contains a dot would
     * not survive an Arr::dot()/Arr::set() round trip, and third-party payloads do
     * contain them.
     *
     * @param array<int|string, mixed> $data
     * @param MaskingRules             $rules
     * @param int                      $mode  the mode an ancestor key already imposed
     *
     * @return array<int|string, mixed>
     */
    private static function maskNode(
        array $data,
        string $prefix,
        array $rules,
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
                $thisMode = max($mode, self::modeFor($path, $rules));
            }

            // an application-controlled key is data too: a cache key is `otp:<email>`
            // often enough, and no key names a key
            $maskedKey = is_string($key)
                ? self::maskByValuePatterns($key, $rules['valuePatterns'])
                : $key;

            if ($maskedKey !== $key && array_key_exists($maskedKey, $result)) {
                // two different keys can mask to the same string - `john@a.com` and
                // `jomn@x.com` both end in `jo******om`. Overwriting would drop an
                // entry silently, which is worse than an ugly key
                $maskedKey .= '#' . (count($result) + 1);
            }

            if (is_array($value)) {
                $result[$maskedKey] = $value === []
                    ? $value
                    : self::maskNode(
                        data: $value,
                        prefix: $path,
                        rules: $rules,
                        mode: $thisMode,
                        depth: $depth + 1
                    );

                continue;
            }

            $result[$maskedKey] = $thisMode === self::MODE_NONE
                ? self::maskUnmatchedString($value, $segment, $rules)
                : self::mask($value, $thisMode);
        }

        return $result;
    }

    /**
     * No key pointed at this value, so look at the value itself: it may carry a
     * structure of its own (a JSON document, a URL query string), and it may contain
     * something that identifies a person by its own shape.
     *
     * @param MaskingRules $rules
     */
    private static function maskUnmatchedString(mixed $value, string $key, array $rules): mixed
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_STRING_LENGTH) {
            return $value;
        }

        if (in_array(Str::lower($key), self::QUERY_STRING_KEYS, true)) {
            // its parameters went through this same path, patterns included
            return self::maskQueryString($value, $rules);
        }

        $masked = self::maskJsonString($value, $rules);

        if ($masked !== $value) {
            // it was a JSON document and something inside it matched; every string in
            // it has already been through here
            return $masked;
        }

        return self::maskByValuePatterns($value, $rules['valuePatterns']);
    }

    /**
     * Masks every occurrence of a value pattern, in place, keeping the rest of the
     * string readable: `Anonymous:mail,john@example.com` stays recognisable as an
     * anonymous mail notifiable while the address itself does not survive.
     *
     * @param string[] $patterns
     */
    private static function maskByValuePatterns(string $value, array $patterns): string
    {
        foreach ($patterns as $pattern) {
            $replaced = @preg_replace_callback(
                $pattern,
                static function (array $matches): string {
                    /** @var string $matched */
                    $matched = $matches[0];

                    /** @var string $masked */
                    $masked = self::mask($matched, self::MODE_PARTIAL);

                    return $masked;
                },
                $value
            );

            if (is_string($replaced)) {
                $value = $replaced;
            }
        }

        return $value;
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
     * @param MaskingRules $rules
     */
    private static function maskJsonString(string $value, array $rules): string
    {
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
            rules: $rules,
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
     * Split by hand rather than through parse_str()/http_build_query(): that round
     * trip rewrites the string even where nothing matched. `user.name` comes back as
     * `user_name` (and then matches `_name`, which the real key never would),
     * `arr[]=1&arr[]=2` becomes `arr%5B0%5D=1&arr%5B1%5D=2`, a valueless `flag` gains
     * an `=`, and a repeated parameter loses all but its last value. A trace that
     * cannot be compared with the request it describes is worth much less.
     *
     * @param MaskingRules $rules
     */
    private static function maskQueryString(string $value, array $rules): string
    {
        $pairs = explode('&', $value);

        $changed = false;

        foreach ($pairs as $index => $pair) {
            if ($pair === '') {
                continue;
            }

            $separator = strpos($pair, '=');

            if ($separator === false) {
                // a valueless parameter: nothing to mask, and adding `=` would change
                // what the request looked like
                continue;
            }

            $rawName  = substr($pair, 0, $separator);
            $rawValue = substr($pair, $separator + 1);

            $name = urldecode($rawName);

            $mode = self::modeFor($name, $rules);

            $decoded = urldecode($rawValue);

            $masked = $mode === self::MODE_NONE
                ? self::maskByValuePatterns($decoded, $rules['valuePatterns'])
                : self::mask($decoded, $mode);

            if (!is_string($masked) || $masked === $decoded) {
                continue;
            }

            // `*` is legal in a query string, and a readable `token=********` beats
            // `token=%2A%2A%2A%2A%2A%2A%2A%2A`
            $pairs[$index] = $rawName . '=' . str_replace('%2A', '*', rawurlencode($masked));

            $changed = true;
        }

        return $changed ? implode('&', $pairs) : $value;
    }

    /**
     * @param MaskingRules $rules
     */
    private static function modeFor(string $key, array $rules): int
    {
        $lowerKey = Str::lower($key);

        if (self::containsAny($lowerKey, $rules['needles'])) {
            return self::MODE_FULL;
        }

        if (self::containsAny($lowerKey, $rules['partialNeedles'])) {
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
