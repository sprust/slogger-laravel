<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Support\Str;

/**
 * The configured key lists, compiled once and asked many times.
 *
 * Compiled because of what masking actually costs: the mode of every key in a trace
 * used to be decided by walking ~60 masks with `Str::is`, which rebuilds its regular
 * expression on each call, once for the whole key and once for each of its word
 * components. A 1 MB JSON body has enough keys to make that seconds of a worker's
 * time. One alternation per list answers the same question in one match, and a key
 * that has been asked about before is not asked about twice.
 */
class MaskingRules
{
    /**
     * Nothing matched.
     */
    public const MODE_NONE = 0;

    /**
     * Enough is left to tell two values apart; the value itself is not recoverable.
     */
    public const MODE_PARTIAL = 1;

    /**
     * Nothing of the value survives.
     */
    public const MODE_FULL = 2;

    /**
     * How many decided keys are remembered. A payload can carry a distinct key per
     * record - a cache key, an id - and remembering all of those would trade a
     * bounded cost for an unbounded one.
     */
    private const MAX_REMEMBERED_KEYS = 10000;

    /**
     * @var string[]
     */
    public readonly array $valuePatterns;

    private readonly ?string $fullPattern;

    private readonly ?string $partialPattern;

    /**
     * @var array<string, int>
     */
    private array $modes = [];

    /**
     * @param array<mixed> $fullKeys
     * @param array<mixed> $partialKeys
     * @param array<mixed> $valuePatterns
     */
    public function __construct(array $fullKeys = [], array $partialKeys = [], array $valuePatterns = [])
    {
        $this->fullPattern    = self::compile($fullKeys);
        $this->partialPattern = self::compile($partialKeys);
        $this->valuePatterns  = self::preparePatterns($valuePatterns);
    }

    public function isEmpty(): bool
    {
        return is_null($this->fullPattern)
            && is_null($this->partialPattern)
            && $this->valuePatterns === [];
    }

    /**
     * What a value under this key gets, and what everything below it inherits.
     *
     * A mask is matched against the whole key **and against each of its word
     * components** - `db_pass`, `x-auth-user` and `apiToken` are split on `_`, `-`,
     * `.`, `:` and camelCase boundaries.
     *
     * Substring search was too broad and could not be narrowed: `auth` also matched
     * `author`, `pass` matched `passengers` and `compass`, and each match took the
     * whole value and its subtree with it. Whole-key masks alone were too narrow:
     * `auth` then stopped matching `php-auth-pw` - the plaintext password Symfony
     * puts beside the base64 header - and `pass` stopped matching `db_pass`.
     *
     * Matching a component gives both: `pass` matches `db_pass` and `smtp_pass`,
     * because `pass` is a word there, and not `passengers` or `compass`, where it is
     * only a prefix.
     */
    public function modeFor(string $key): int
    {
        if (isset($this->modes[$key])) {
            return $this->modes[$key];
        }

        $mode = $this->resolveMode($key);

        if (count($this->modes) < self::MAX_REMEMBERED_KEYS) {
            $this->modes[$key] = $mode;
        }

        return $mode;
    }

    private function resolveMode(string $key): int
    {
        $lowerKey = Str::lower($key);

        $components = self::keyComponents($key);

        if (self::matches($this->fullPattern, $lowerKey, $components)) {
            // the stricter list wins: a partial mask on a token is not a mask
            return self::MODE_FULL;
        }

        if (self::matches($this->partialPattern, $lowerKey, $components)) {
            return self::MODE_PARTIAL;
        }

        return self::MODE_NONE;
    }

    /**
     * @param string[] $components
     */
    private static function matches(?string $pattern, string $lowerKey, array $components): bool
    {
        if (is_null($pattern)) {
            return false;
        }

        if (preg_match($pattern, $lowerKey) === 1) {
            return true;
        }

        foreach ($components as $component) {
            if (preg_match($pattern, $component) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * One alternation for a whole list, or null when the list has nothing usable in
     * it. `*` keeps its `Str::is` meaning - any run of characters, none included.
     *
     * Tolerates a hand-written list: these come from a published config, they are
     * read inside the dispatcher job, and a stray non-string must not take a whole
     * batch of traces down with it.
     *
     * @param array<mixed> $keys
     */
    private static function compile(array $keys): ?string
    {
        $alternatives = [];

        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $alternatives[] = str_replace('\*', '.*', preg_quote(Str::lower($key), '#'));
        }

        if (!$alternatives) {
            return null;
        }

        return '#\A(?:' . implode('|', $alternatives) . ')\z#u';
    }

    /**
     * Drops anything that is not a usable regular expression. A typo in a configured
     * pattern would otherwise raise a warning for every string in every trace.
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
     * The word components of a key, lowercased.
     *
     * @return string[]
     */
    private static function keyComponents(string $key): array
    {
        $split = preg_split('/[^\p{L}\p{N}]+|(?<=[\p{Ll}\p{N}])(?=\p{Lu})/u', $key) ?: [];

        return array_values(
            array_filter(
                array_map(static fn(string $part): string => Str::lower($part), $split),
                static fn(string $part): bool => $part !== ''
            )
        );
    }
}
