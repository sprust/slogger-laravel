<?php

namespace SLoggerLaravel\Helpers;

use SLoggerLaravel\Configs\MaskingConfig;
use SLoggerLaravel\Objects\TracesObject;

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
     * @var string[]
     */
    private readonly array $fullKeys;

    /**
     * @var string[]
     */
    private readonly array $partialKeys;

    /**
     * @var string[]
     */
    private readonly array $valuePatterns;

    private readonly bool $enabled;

    public function __construct(MaskingConfig $config)
    {
        $this->fullKeys      = $config->getFullKeys();
        $this->partialKeys   = $config->getPartialKeys();
        $this->valuePatterns = $config->getValuePatterns();

        $this->enabled = $this->fullKeys !== []
            || $this->partialKeys !== []
            || $this->valuePatterns !== [];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function maskTraces(TracesObject $traces): TracesObject
    {
        if (!$this->enabled) {
            return $traces;
        }

        $masked = new TracesObject();

        foreach ($traces->iterateCreating() as $trace) {
            $trace->data = $this->mask($trace->data);

            $masked->addCreating($trace);
        }

        foreach ($traces->iterateUpdating() as $trace) {
            if (!is_null($trace->data)) {
                $trace->data = $this->mask($trace->data);
            }

            $masked->addUpdating($trace);
        }

        return $masked;
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
        $masked = MaskHelper::maskArrayByKeys(
            data: $data,
            fullKeys: $this->fullKeys,
            partialKeys: $this->partialKeys,
            valuePatterns: $this->valuePatterns
        );

        return $masked;
    }
}
