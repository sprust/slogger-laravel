<?php

namespace SLoggerLaravel\Watchers;

use Closure;

/**
 * What one watcher has open, by trace id, innermost last.
 *
 * Every parent watcher needs the same thing - the trace it started, plus whatever it
 * has to remember until the matching finish event arrives - and each one used to do
 * it differently: two kept a stack in the trace scope, one a map on itself, one a map
 * that repeated the key inside the value. A stack in the scope was a second copy of
 * the processor's own stack, kept in step with it by hand.
 *
 * Keyed by trace id and held by the watcher, so it needs no scope of its own: a trace
 * id is unique across the process, which is what lets one map serve every unit of work
 * at once - exactly the argument that keeps the processor's detached traces in one
 * map. Insertion order is the nesting order, so "the innermost" is still a question
 * this can answer.
 */
class OpenTraces
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $items = [];

    /**
     * @param array<string, mixed> $meta
     */
    public function open(string $traceId, array $meta = []): void
    {
        $this->items[$traceId] = $meta;
    }

    /**
     * Takes back one entry by trace id - for a watcher that is told which trace it is
     * closing, as an outbound request is by the header it carries.
     *
     * @return array<string, mixed>|null the entry with `trace_id` in it
     */
    public function take(string $traceId): ?array
    {
        $meta = $this->items[$traceId] ?? null;

        if (is_null($meta)) {
            return null;
        }

        unset($this->items[$traceId]);

        return ['trace_id' => $traceId, ...$meta];
    }

    /**
     * Takes back the innermost entry the predicate accepts, and drops everything
     * opened after it.
     *
     * Taking the innermost one blindly assumes whoever opened an entry also closes it.
     * A command killed mid-run, or one whose finish event never fires, breaks that:
     * the next finish would take the abandoned entry, close a trace that is not its
     * own, and leave its own open forever. What sits above the match is abandoned by
     * definition - the processor sweeps those traces as interrupted.
     *
     * @param Closure(array<string, mixed>): bool|null $matches
     *
     * @return array<string, mixed>|null the entry with `trace_id` in it
     */
    public function takeInnermost(?Closure $matches = null): ?array
    {
        $abandoned = [];

        foreach (array_reverse(array_keys($this->items)) as $traceId) {
            $meta = $this->items[$traceId];

            if ($matches && !$matches($meta)) {
                $abandoned[] = $traceId;

                continue;
            }

            foreach ([...$abandoned, $traceId] as $droppedTraceId) {
                unset($this->items[$droppedTraceId]);
            }

            return ['trace_id' => $traceId, ...$meta];
        }

        return null;
    }

    /**
     * Drops the entry of a trace closed without the watcher hearing about it - by the
     * processor's sweep. Left in place, the next take would find a trace that is
     * already gone.
     */
    public function forget(string $traceId): void
    {
        unset($this->items[$traceId]);
    }

    /**
     * How many are open. What a leak looks like from the outside: a long-lived worker
     * whose count only ever goes up.
     */
    public function count(): int
    {
        return count($this->items);
    }
}
