<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Traces;

use Fiber;
use SLoggerLaravel\Traces\FiberTraceScopeResolver;
use SLoggerLaravel\Traces\TraceScope;

/**
 * A coroutine context, of the shape a concurrent runtime provides, so the package's
 * side of the contract can be tested without depending on any runtime.
 *
 * Two properties are what an integration has to supply, and both are reproduced
 * here: a map per fiber, and reads that walk up the parent chain. The parent is
 * recorded when the coroutine is spawned.
 */
class FakeCoroutineScopeResolver extends FiberTraceScopeResolver
{
    /**
     * @var array<int, TraceScope>
     */
    private array $scopes = [];

    /**
     * @var array<int, int>
     */
    private array $parents = [];

    /**
     * Starts a coroutine whose context inherits from the one spawning it.
     *
     * @return Fiber<mixed, mixed, mixed, mixed>
     */
    public function spawn(callable $callback): Fiber
    {
        $parentId = $this->currentOwnerId();

        $fiber = new Fiber($callback);

        $this->parents[spl_object_id($fiber)] = $parentId;

        return $fiber;
    }

    protected function read(): ?TraceScope
    {
        $ownerId = $this->currentOwnerId();

        while (true) {
            if (isset($this->scopes[$ownerId])) {
                return $this->scopes[$ownerId];
            }

            if (!isset($this->parents[$ownerId])) {
                return null;
            }

            $ownerId = $this->parents[$ownerId];
        }
    }

    protected function write(TraceScope $scope): void
    {
        $this->scopes[$this->currentOwnerId()] = $scope;
    }
}
