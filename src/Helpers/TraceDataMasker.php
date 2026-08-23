<?php

namespace SLoggerLaravel\Helpers;

use SLoggerLaravel\Configs\MaskingConfig;
use SLoggerLaravel\Objects\TracesObject;
use Throwable;

/**
 * Masks trace data by the globally configured key lists.
 *
 * It runs in the dispatcher job, right before the batch is sent, and never in the
 * traced application: masking a payload is the expensive part, and the traced
 * process should not pay for it. Traces therefore sit in the queue with whatever the
 * watchers collected - see the security note in the README.
 */
class TraceDataMasker
{
    /**
     * Replaces the data of a trace the masker could not read, so the trace still
     * arrives and says why it is empty.
     */
    public const MASK_ERROR_KEY = '__mask_error';

    private readonly MaskingRules $rules;

    private readonly bool $enabled;

    public function __construct(MaskingConfig $config)
    {
        // compiled once, for the life of the process: the lists are validated and
        // turned into one regular expression each here rather than on every trace
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
     * Masks a batch in place and hands the same object back.
     *
     * Every trace is masked on its own, and one that cannot be is replaced by a note
     * saying so. Masking is deterministic - a payload that breaks it breaks it again
     * on every retry - so letting the exception out cost the whole batch, five times
     * over, and the traces around the broken one with it. What ships instead says
     * nothing about what was in there, which is the safe direction to fail in.
     */
    public function maskTraces(TracesObject $traces): TracesObject
    {
        if (!$this->enabled) {
            return $traces;
        }

        foreach ($traces->iterateCreating() as $trace) {
            $trace->data = $this->maskTraceData($trace->data);
            $trace->tags = $this->maskTags($trace->tags);
        }

        foreach ($traces->iterateUpdating() as $trace) {
            if (!is_null($trace->data)) {
                $trace->data = $this->maskTraceData($trace->data);
            }

            if (!is_null($trace->tags)) {
                $trace->tags = $this->maskTags($trace->tags);
            }
        }

        return $traces;
    }

    /**
     * Tags are bare strings - a url, a sql fragment, a cache key - with no key naming
     * them, so the key lists cannot reach them. The value patterns can: what
     * identifies a person by its own shape is just as recognisable in a tag.
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
}
