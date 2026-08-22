<?php

namespace SLoggerLaravel\Traces;

/**
 * Where traces started right now hang from.
 *
 * The object is process-wide; the value it holds is not. It lives in the trace
 * scope, so under a concurrent runtime each coroutine reads and writes its own -
 * otherwise a coroutine waking up from an async call would find the parent trace id
 * of whichever one ran while it was suspended.
 */
class TraceIdContainer
{
    public function __construct(
        private readonly TraceScopeResolverInterface $scopeResolver
    ) {
    }

    public function getParentTraceId(): ?string
    {
        return $this->scopeResolver->current()->parentTraceId;
    }

    public function setParentTraceId(?string $parentTraceId): static
    {
        $scope = $this->scopeResolver->current();

        $scope->preParentTraceId = $scope->parentTraceId;
        $scope->parentTraceId    = $parentTraceId;

        return $this;
    }

    public function getPreParentTraceId(): ?string
    {
        return $this->scopeResolver->current()->preParentTraceId;
    }

    public function reset(): void
    {
        $scope = $this->scopeResolver->current();

        $scope->parentTraceId    = null;
        $scope->preParentTraceId = null;
    }
}
