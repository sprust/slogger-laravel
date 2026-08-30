<?php

namespace SLoggerLaravel\Context;

/**
 * Where the state of one unit of work is kept - one request, one job, one command.
 *
 * A process that runs them one at a time has no use for this: the default store is a
 * single array, and there "the current unit" is the process. A process that runs
 * several at once, each in its own coroutine, needs each to have its own state, and
 * making that swappable is the only reason this interface exists.
 *
 * A store is a singleton and holds no state of the unit itself: it works out where to
 * look on every call. That is what makes the references captured at bootstrap - the
 * Processor a watcher was built with, the closures registered as listeners - harmless.
 *
 * @see ArrayTraceContext  one array, for a process that runs one unit at a time
 * @see FiberTraceContext  one array per fiber, and the same one array without them
 */
interface TraceContextInterface
{
    /**
     * The default is returned only when the key is absent. A key holding null gives
     * back null, which is not the same answer.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Replaces whatever is under the key, including with null.
     *
     * There is no way to remove a key on purpose, and nothing here wants one: a store
     * whose reads fall through to an enclosing unit of work - a coroutine context with
     * inheritance usually does - would answer with the enclosing unit's value again.
     * "There is nothing here" is written as null, or as an empty list.
     */
    public function set(string $key, mixed $value): void;
}
