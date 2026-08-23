<?php

namespace SLoggerLaravel\Traces;

use Illuminate\Support\Carbon;

/**
 * All of a tracing unit's mutable state, in one object.
 *
 * A "unit" is whatever runs one logical piece of work start to finish: a process
 * under PHP-FPM or `queue:work`, a coroutine under a concurrent runtime. Keeping
 * the state here rather than in the singletons themselves is what makes the second
 * case possible - the objects stay process-wide, the state does not.
 */
class TraceScope
{
    /**
     * Currently started parent traces, from the outermost to the innermost one.
     *
     * @var list<array{trace_id: string, pre_parent_trace_id: string|null, tags: string[], logged_at: Carbon}>
     */
    public array $tracesStack = [];

    public bool $paused = false;

    public ?string $parentTraceId = null;

    /**
     * Values the application asked to be added to every trace of this unit of work.
     *
     * Per unit, not per process: these are `user_id`, `tenant`, `request_id` - the
     * things a request has and the next one does not. Living anywhere else, one
     * request's value stamped every later request in the same worker, and every
     * concurrent coroutine besides - which is why they are dropped by
     * endUnitOfWork(). Callbacks are the other half of the story and are kept by
     * the complementer, for the process.
     *
     * @var array<string, mixed>
     */
    public array $additional = [];

    /**
     * @param int $ownerId identifies the unit this scope belongs to; see
     *                     TraceScopeResolverInterface::current()
     */
    public function __construct(public readonly int $ownerId = 0)
    {
    }

    /**
     * Nothing is open here and nothing encloses this scope: whatever the unit of
     * work carried belongs to it alone and must not reach the next one.
     *
     * A process-wide scope - the default one - outlives the unit, so this is the
     * only thing that separates one job from the next in a `queue:work` worker.
     */
    public function endUnitOfWork(): void
    {
        $this->additional = [];
    }
}
