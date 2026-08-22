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

    public ?string $preParentTraceId = null;

    /**
     * Bookkeeping the parent watchers keep per unit of work: a stack of open
     * commands, of open requests, and so on. Keyed by watcher class.
     *
     * @var array<class-string, list<mixed>>
     */
    private array $watcherStacks = [];

    /**
     * @param int $ownerId identifies the unit this scope belongs to; see
     *                     TraceScopeResolverInterface::current()
     */
    public function __construct(public readonly int $ownerId = 0)
    {
    }

    /**
     * The trace a child started here would hang under, inherited by a scope that
     * this one spawns.
     */
    public function currentParentTraceId(): ?string
    {
        return $this->parentTraceId;
    }

    /**
     * Records something a parent watcher has open - a started command, a started
     * request - for as long as this unit of work lasts.
     *
     * @param class-string $watcherClass
     */
    public function pushWatcherItem(string $watcherClass, mixed $item): void
    {
        $this->watcherStacks[$watcherClass][] = $item;
    }

    /**
     * Takes back the innermost one, or null when the watcher has nothing open here.
     *
     * @param class-string $watcherClass
     */
    public function popWatcherItem(string $watcherClass): mixed
    {
        if (!($this->watcherStacks[$watcherClass] ?? [])) {
            return null;
        }

        return array_pop($this->watcherStacks[$watcherClass]);
    }
}
