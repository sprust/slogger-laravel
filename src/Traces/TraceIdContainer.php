<?php

namespace SLoggerLaravel\Traces;

/**
 * Where traces started right now hang from.
 *
 * Read by whatever carries the id elsewhere - an outbound header, a queued job's
 * payload - so a trace started over there joins this tree.
 */
class TraceIdContainer
{
    private ?string $parentTraceId = null;

    public function getParentTraceId(): ?string
    {
        return $this->parentTraceId;
    }

    public function setParentTraceId(?string $parentTraceId): void
    {
        $this->parentTraceId = $parentTraceId;
    }
}
