<?php

namespace SLoggerLaravel\RequestPreparer;

use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\MaskingRules;

/**
 * The masks one formatter slot carries - the headers of a request, the fields of a
 * response - for the urls that formatter matches.
 *
 * The global `masking` lists still run afterwards, in the dispatcher job. These are
 * for what a single client needs and the global lists should not carry: a key that is a
 * secret at one partner and a plain identifier everywhere else. The three lists mean
 * exactly what they mean in the `masking` config section.
 *
 * Immutable: the compiled rules are built once and asked many times.
 */
class Masks
{
    /**
     * @var string[]
     */
    private array $fullKeys;

    /**
     * @var string[]
     */
    private array $partialKeys;

    /**
     * @var string[]
     */
    private array $valuePatterns;

    private ?MaskingRules $rules = null;

    /**
     * @param array<mixed> $fullKeys
     * @param array<mixed> $partialKeys
     * @param array<mixed> $valuePatterns
     */
    public function __construct(array $fullKeys = [], array $partialKeys = [], array $valuePatterns = [])
    {
        $this->fullKeys      = self::strings($fullKeys);
        $this->partialKeys   = self::strings($partialKeys);
        $this->valuePatterns = self::strings($valuePatterns);
    }

    /**
     * From what a config or a caller hands over, or null when it configures nothing.
     *
     * A plain list is a list of full masks - the shape the pre-2.0 arguments had, and
     * the common case. The long form names the lists:
     *
     * ```php
     * ['full_keys' => [...], 'partial_keys' => [...], 'value_patterns' => [...]]
     * ```
     */
    public static function from(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value->isEmpty() ? null : $value;
        }

        if (!is_array($value)) {
            return null;
        }

        $named = array_key_exists('full_keys', $value)
            || array_key_exists('partial_keys', $value)
            || array_key_exists('value_patterns', $value);

        $masks = $named
            ? new self(
                fullKeys: self::listAt($value, 'full_keys'),
                partialKeys: self::listAt($value, 'partial_keys'),
                valuePatterns: self::listAt($value, 'value_patterns')
            )
            : new self(fullKeys: $value);

        return $masks->isEmpty() ? null : $masks;
    }

    /**
     * Both lists of both, as a new instance: an `add*()` on a formatter has to leave
     * whatever was configured before it in place.
     */
    public function merge(self $other): self
    {
        return new self(
            fullKeys: [...$this->fullKeys, ...$other->fullKeys],
            partialKeys: [...$this->partialKeys, ...$other->partialKeys],
            valuePatterns: [...$this->valuePatterns, ...$other->valuePatterns]
        );
    }

    public function isEmpty(): bool
    {
        return !$this->fullKeys && !$this->partialKeys && !$this->valuePatterns;
    }

    /**
     * The top level of what is passed in is the application's own - a header bag, a
     * decoded body - so it is matched like every level below it.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    public function apply(array $data): array
    {
        if (!$data || $this->isEmpty()) {
            return $data;
        }

        return MaskHelper::maskDataByRules($data, $this->rules());
    }

    /**
     * Compiled on first use: a formatter whose url never matches pays nothing.
     */
    public function rules(): MaskingRules
    {
        return $this->rules ??= new MaskingRules(
            fullKeys: $this->fullKeys,
            partialKeys: $this->partialKeys,
            valuePatterns: $this->valuePatterns
        );
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function listAt(array $value, string $key): array
    {
        $list = $value[$key] ?? [];

        if (is_string($list)) {
            return [$list];
        }

        return is_array($list) ? $list : [];
    }

    /**
     * A stray non-string must not reach the compiler.
     *
     * @param array<mixed> $keys
     *
     * @return string[]
     */
    private static function strings(array $keys): array
    {
        return array_values(
            array_filter(
                $keys,
                static fn(mixed $key): bool => is_string($key) && $key !== ''
            )
        );
    }
}
