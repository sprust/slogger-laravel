<?php

namespace SLoggerLaravel\Traces;

use SLoggerLaravel\Context\TraceContextInterface;

/**
 * Where traces started right now hang from.
 *
 * Read by whatever carries the id elsewhere - an outbound header, a queued job's
 * payload - so a trace started over there joins this tree.
 *
 * The id belongs to one unit of work, not to the process: two requests handled at once
 * in one process each have their own, or the second would start under the first.
 */
class TraceIdContainer
{
    private const CONTEXT_KEY_PARENT_TRACE_ID = 'slogger.trace.parent_id';

    public function __construct(
        private readonly TraceContextInterface $context
    ) {
    }

    public function getParentTraceId(): ?string
    {
        $parentTraceId = $this->context->get(self::CONTEXT_KEY_PARENT_TRACE_ID);

        return is_string($parentTraceId) ? $parentTraceId : null;
    }

    public function setParentTraceId(?string $parentTraceId): void
    {
        // written, not forgotten, when it is null: a store whose reads fall through to
        // an enclosing unit would otherwise start answering with that unit's id again
        $this->context->set(self::CONTEXT_KEY_PARENT_TRACE_ID, $parentTraceId);
    }
}
