<?php

namespace SLoggerLaravel\Traces;

/**
 * Resolves the trace scope of whatever is running right now.
 *
 * There is one implementation per concurrency model. Under a plain process - FPM,
 * `queue:work`, an artisan command - one scope serves the whole process, which is
 * what the package has always done and what it binds by default. Under a runtime
 * that interleaves coroutines in one process, each coroutine needs its own, or two
 * of them overwrite each other's parent trace id and close each other's traces;
 * such an application rebinds this interface with a resolver of its own, and
 * FiberTraceScopeResolver is there to build it on.
 */
interface TraceScopeResolverInterface
{
    /**
     * The current unit of work's scope, created on first use.
     */
    public function current(): TraceScope;

    /**
     * Whether scopes are actually isolated from one another. False for the plain
     * process resolver, where "the current scope" is always the same object.
     */
    public function isConcurrent(): bool;
}
