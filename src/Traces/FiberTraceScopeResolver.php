<?php

namespace SLoggerLaravel\Traces;

use Fiber;
use WeakMap;

/**
 * A scope per coroutine, for runtimes that run each unit of work in its own Fiber.
 *
 * The package ships no such runtime and knows about none: what it offers here is
 * the rule, already worked out, so an integration only has to supply the store -
 * read() returns the scope visible from the current coroutine (its own, or the
 * nearest ancestor's), write() saves one against the current coroutine.
 *
 * The rule has two halves, and both matter:
 *
 * - a coroutine gets its **own** scope, not the one it inherited. The stack is
 *   popped, and two coroutines popping one stack close each other's traces.
 * - it **inherits the parent trace id** from the coroutine that spawned it, so a
 *   call made inside a coroutine hangs under the trace that started it instead of
 *   arriving as an orphan.
 */
abstract class FiberTraceScopeResolver implements TraceScopeResolverInterface
{
    /**
     * Outside any fiber - bootstrap, the worker loop itself - every caller shares
     * one scope, the same way a plain process does.
     */
    protected const ROOT_OWNER_ID = 0;

    /**
     * Owner id per live fiber.
     *
     * Not `spl_object_id`: PHP hands the id of a collected object to the next one
     * allocated, and three fibers created in sequence routinely get the same id. A
     * scope left in the store by a finished coroutine would then be adopted by an
     * unrelated new one - which inherits a stranger's stack, hangs its children
     * under a dead trace, and sweeps that stranger's open traces as interrupted when
     * it stops. The ids handed out here are never reused, and the map drops an entry
     * when its fiber is collected.
     *
     * @var WeakMap<object, int>|null
     */
    private ?WeakMap $ownerIds = null;

    private int $lastOwnerId = self::ROOT_OWNER_ID;

    /**
     * The scope visible from here: this coroutine's own if it has one, otherwise the
     * nearest ancestor's, or null.
     */
    abstract protected function read(): ?TraceScope;

    abstract protected function write(TraceScope $scope): void;

    public function current(): TraceScope
    {
        $ownerId = $this->currentOwnerId();

        $found = $this->read();

        if ($found instanceof TraceScope && $found->ownerId === $ownerId) {
            return $found;
        }

        $scope = new TraceScope(ownerId: $ownerId);

        if ($found instanceof TraceScope) {
            $scope->parentTraceId = $found->parentTraceId;
        }

        $this->write($scope);

        return $scope;
    }

    public function isConcurrent(): bool
    {
        return true;
    }

    protected function currentOwnerId(): int
    {
        $fiber = Fiber::getCurrent();

        return is_null($fiber)
            ? static::ROOT_OWNER_ID
            : $this->ownerIdOf($fiber);
    }

    /**
     * The id of a coroutine that is not the running one - a resolver that has to
     * record a parent at spawn time needs this, before the child has ever run.
     *
     * @param Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    protected function ownerIdOf(Fiber $fiber): int
    {
        /** @var WeakMap<object, int> $ownerIds */
        $ownerIds = $this->ownerIds ??= new WeakMap();

        if (!isset($ownerIds[$fiber])) {
            $ownerIds[$fiber] = ++$this->lastOwnerId;
        }

        /** @var int $ownerId */
        $ownerId = $ownerIds[$fiber];

        return $ownerId;
    }
}
