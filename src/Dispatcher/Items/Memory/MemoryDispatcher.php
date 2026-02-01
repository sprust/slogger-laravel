<?php

namespace SLoggerLaravel\Dispatcher\Items\Memory;

use RuntimeException;
use SLoggerLaravel\Dispatcher\Items\DispatcherProcessorInterface;
use SLoggerLaravel\Dispatcher\Items\TraceDispatcherInterface;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;

class MemoryDispatcher implements TraceDispatcherInterface
{
    /**
     * @var list<TraceCreateObject>
     */
    private array $creatingTraces = [];
    /**
     * @var list<TraceUpdateObject>
     */
    private array $updatingTraces = [];

    public function getProcessor(): DispatcherProcessorInterface
    {
        throw new RuntimeException('Not supported.');
    }

    public function create(TraceCreateObject $parameters): void
    {
        $this->creatingTraces[] = $parameters;
    }

    public function update(TraceUpdateObject $parameters): void
    {
        $this->updatingTraces[] = $parameters;
    }

    /**
     * @return list<TraceCreateObject>
     */
    public function getCreatingTraces(): array
    {
        return $this->creatingTraces;
    }

    /**
     * @return list<TraceUpdateObject>
     */
    public function getUpdatingTraces(): array
    {
        return $this->updatingTraces;
    }

    public function flush(): void
    {
        $this->creatingTraces = [];
        $this->updatingTraces = [];
    }
}
