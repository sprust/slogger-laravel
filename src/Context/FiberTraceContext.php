<?php

namespace SLoggerLaravel\Context;

use Fiber;
use WeakMap;

/**
 * State per running fiber, and a single array when none is running.
 *
 * The second half is the point: with no fiber current this is byte for byte an
 * ArrayTraceContext, so php-fpm, `artisan` and `queue:work` behave exactly as they did
 * before there was a store at all. Under a runtime that gives each request or each job
 * its own fiber, the same code keeps them apart.
 *
 * Reads do not fall through to the fiber that created this one. There is no way to ask
 * PHP who that was, and guessing is how unrelated traces get stitched into one tree -
 * the very thing this store exists to stop.
 *
 * That has a price, and it is why this is not the default. A fiber started inside a
 * traced request begins with nothing: a parent trace opened there is recorded as a root
 * rather than nested under the request, and a child trace pushed there is not recorded
 * at all - `Processor::push()` finds no open parent in this fiber and returns. Set this
 * store for a runtime that gives each unit of work its own fiber, not for one that runs
 * fibers inside a unit of work. A host that can name the enclosing coroutine should
 * bind a store of its own.
 *
 * @see TraceContextInterface
 */
class FiberTraceContext implements TraceContextInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $rootValues = [];

    /**
     * Weak on purpose: the entry goes when the fiber is collected, so a long-running
     * process accumulates nothing and there is no handle to release by hand.
     *
     * Keyed by object rather than by Fiber: `Fiber::getCurrent()` hands back an
     * unparameterised Fiber, and a WeakMap keyed by one refuses the assignment.
     *
     * @var WeakMap<object, array<string, mixed>>
     */
    private readonly WeakMap $fiberValues;

    public function __construct()
    {
        $this->fiberValues = new WeakMap();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->readValues();

        return array_key_exists($key, $values)
            ? $values[$key]
            : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $values = $this->readValues();

        $values[$key] = $value;

        $this->writeValues($values);
    }

    /**
     * @return array<string, mixed>
     */
    private function readValues(): array
    {
        $fiber = Fiber::getCurrent();

        if (is_null($fiber)) {
            return $this->rootValues;
        }

        return $this->fiberValues[$fiber] ?? [];
    }

    /**
     * Whole map at a time, and never across a suspension point: a fiber that resumed
     * with a map read before it yielded would write back what it had then.
     *
     * @param array<string, mixed> $values
     */
    private function writeValues(array $values): void
    {
        $fiber = Fiber::getCurrent();

        if (is_null($fiber)) {
            $this->rootValues = $values;

            return;
        }

        $this->fiberValues[$fiber] = $values;
    }
}
