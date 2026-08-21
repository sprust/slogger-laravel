<?php

namespace SLoggerLaravel\Helpers;

use SLoggerLaravel\Configs\MaskingConfig;
use SLoggerLaravel\Objects\TracesObject;

/**
 * Masks trace data by the globally configured key list.
 *
 * It runs in the dispatcher job, right before the batch is sent, and never in the
 * traced application: masking a payload is the expensive part, and the traced
 * process should not pay for it. Traces sit in the queue unmasked, which is why
 * the job carrying them is encrypted.
 */
class TraceDataMasker
{
    /**
     * @var string[]
     */
    private readonly array $keys;

    public function __construct(MaskingConfig $config)
    {
        $this->keys = $config->getKeys();
    }

    public function isEnabled(): bool
    {
        return $this->keys !== [];
    }

    public function maskTraces(TracesObject $traces): TracesObject
    {
        if (!$this->isEnabled()) {
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
        if (!$data || !$this->isEnabled()) {
            return $data;
        }

        /** @var array<string, mixed> $masked */
        $masked = MaskHelper::maskArrayByKeys(
            data: $data,
            keys: $this->keys
        );

        return $masked;
    }
}
