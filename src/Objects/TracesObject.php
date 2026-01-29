<?php

declare(strict_types=1);

namespace SLoggerLaravel\Objects;

use Generator;

class TracesObject
{
    /** @var TraceCreateObject[] */
    private array $creating = [];

    /** @var TraceUpdateObject[] */
    private array $updating = [];

    public function addCreating(TraceCreateObject $trace): static
    {
        $this->creating[] = $trace;

        return $this;
    }

    public function addUpdating(TraceUpdateObject $trace): static
    {
        $this->updating[] = $trace;

        return $this;
    }

    /**
     * @return Generator<int, TraceCreateObject>
     */
    public function iterateCreating(): Generator
    {
        while ($trace = array_shift($this->creating)) {
            yield $trace;
        }
    }

    /**
     * @return Generator<int, TraceUpdateObject>
     */
    public function iterateUpdating(): Generator
    {
        while ($trace = array_shift($this->updating)) {
            yield $trace;
        }
    }

    public function count(): int
    {
        return count($this->creating) + count($this->updating);
    }
}


