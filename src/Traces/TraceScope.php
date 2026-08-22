<?php

namespace SLoggerLaravel\Traces;

use Closure;
use Illuminate\Support\Carbon;
use SLoggerLaravel\Watchers\WatcherInterface;

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
     * Values the application asked to be added to every trace of this unit of work.
     *
     * Per unit, not per process: these are `user_id`, `tenant`, `request_id` - the
     * things a request has and the next one does not. Held on the complementer, one
     * request's value stamped every later request in the same worker, and every
     * concurrent coroutine besides.
     *
     * @var array<string, mixed>
     */
    public array $additional = [];

    /**
     * Bookkeeping the parent watchers keep per unit of work: a stack of open
     * commands, of open requests, and so on. Keyed by watcher class.
     *
     * @var array<class-string<WatcherInterface>, list<mixed>>
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
     * The watcher passes itself rather than a class name: a subclass then gets its
     * own stack instead of writing into its parent's, and no caller can reach
     * another watcher's entries by naming its class.
     */
    public function pushWatcherItem(WatcherInterface $watcher, mixed $item): void
    {
        $this->watcherStacks[$watcher::class][] = $item;
    }

    /**
     * Takes back the innermost one, or null when the watcher has nothing open here.
     */
    public function popWatcherItem(WatcherInterface $watcher): mixed
    {
        if (!($this->watcherStacks[$watcher::class] ?? [])) {
            return null;
        }

        return array_pop($this->watcherStacks[$watcher::class]);
    }

    /**
     * Takes back the innermost entry the predicate accepts, and drops everything
     * above it.
     *
     * Popping blindly assumes every unit that opened one also closes it. A command
     * killed mid-run, or one whose finish event never fires, breaks that: the next
     * finish would take the abandoned entry, close a trace that is not its own, and
     * leave its own open forever. What sits above the match is abandoned by
     * definition - the processor sweeps those traces as interrupted.
     *
     * @param Closure(mixed): bool $matches
     */
    public function popWatcherItemMatching(WatcherInterface $watcher, Closure $matches): mixed
    {
        $stack = $this->watcherStacks[$watcher::class] ?? [];

        for ($index = count($stack) - 1; $index >= 0; $index--) {
            if (!$matches($stack[$index])) {
                continue;
            }

            $this->watcherStacks[$watcher::class] = array_slice($stack, 0, $index);

            return $stack[$index];
        }

        return null;
    }

    /**
     * Drops the entries a watcher holds for a trace that was closed without it - by
     * the sweep, not by the watcher itself. Left in place, the next pop would take a
     * stale entry and leave the watcher's own trace open forever.
     */
    public function forgetWatcherItemsFor(WatcherInterface $watcher, string $traceId): void
    {
        if (!($this->watcherStacks[$watcher::class] ?? [])) {
            return;
        }

        $this->watcherStacks[$watcher::class] = array_values(
            array_filter(
                $this->watcherStacks[$watcher::class],
                static fn(mixed $item): bool => !is_array($item)
                    || ($item['trace_id'] ?? null) !== $traceId
            )
        );
    }
}
