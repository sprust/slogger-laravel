<?php

namespace SLoggerLaravel\Traces;

class TraceIdContainer
{
    private ?string $parentTraceId    = null;
    private ?string $preParentTraceId = null;

    public function getParentTraceId(): ?string
    {
        return $this->parentTraceId;
    }

    public function setParentTraceId(?string $parentTraceId): static
    {
        $this->preParentTraceId = $this->parentTraceId;
        $this->parentTraceId    = $parentTraceId;

        return $this;
    }

    public function getPreParentTraceId(): ?string
    {
        return $this->preParentTraceId;
    }

    public function reset(): void
    {
        $this->parentTraceId    = null;
        $this->preParentTraceId = null;
    }
}
