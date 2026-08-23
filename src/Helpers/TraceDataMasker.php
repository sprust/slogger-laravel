<?php

namespace SLoggerLaravel\Helpers;

use SLoggerLaravel\Configs\MaskingConfig;
use SLoggerLaravel\Objects\TracesObject;
use Throwable;

/**
 * In the dispatcher job, never in the traced application - which should not pay for
 * it. Traces sit in the queue unmasked; see the security note in the README.
 */
class TraceDataMasker
{
    /** Replaces the data of a trace the masker could not read. */
    public const MASK_ERROR_KEY = '__mask_error';

    private readonly MaskingRules $rules;

    private readonly bool $enabled;

    public function __construct(MaskingConfig $config)
    {
        // compiled once for the life of the process, not per trace
        $this->rules = new MaskingRules(
            fullKeys: $config->getFullKeys(),
            partialKeys: $config->getPartialKeys(),
            valuePatterns: $config->getValuePatterns()
        );

        $this->enabled = !$this->rules->isEmpty();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * In place. A trace that cannot be masked is replaced by a note: masking is
     * deterministic, so letting the exception out cost the batch and every retry.
     */
    public function maskTraces(TracesObject $traces): TracesObject
    {
        if (!$this->enabled) {
            return $traces;
        }

        foreach ($traces->iterateCreating() as $trace) {
            $trace->data = $this->maskTraceData($trace->data);
            $trace->tags = $this->maskTraceTags($trace->tags);
        }

        foreach ($traces->iterateUpdating() as $trace) {
            if (!is_null($trace->data)) {
                $trace->data = $this->maskTraceData($trace->data);
            }

            if (!is_null($trace->tags)) {
                $trace->tags = $this->maskTraceTags($trace->tags);
            }
        }

        return $traces;
    }

    /**
     * Tags are bare strings with no key naming them, so only the value patterns reach
     * them.
     *
     * @param string[] $tags
     *
     * @return string[]
     */
    public function maskTags(array $tags): array
    {
        if (!$tags || !$this->rules->valuePatterns) {
            return $tags;
        }

        return array_map(
            fn(string $tag): string => MaskHelper::maskStringByRules($tag, $this->rules),
            $tags
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function mask(array $data): array
    {
        if (!$data || !$this->enabled) {
            return $data;
        }

        /** @var array<string, mixed> $masked */
        $masked = MaskHelper::maskArrayByRules($data, $this->rules);

        return $masked;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function maskTraceData(array $data): array
    {
        try {
            return $this->mask($data);
        } catch (Throwable $exception) {
            return [
                self::MASK_ERROR_KEY => $exception->getMessage(),
            ];
        }
    }

    /**
     * Under the same guard as the data: a tag that is not a string threw straight out
     * of maskTraces() and cost the batch.
     *
     * @param string[] $tags
     *
     * @return string[]
     */
    private function maskTraceTags(array $tags): array
    {
        try {
            return $this->maskTags($tags);
        } catch (Throwable) {
            return [self::MASK_ERROR_KEY];
        }
    }
}
