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
     * @var array<string, TraceCreateObject>
     */
    private array $creatingTraces = [];
    /**
     * @var array<string, TraceUpdateObject>
     */
    private array $updatingTraces = [];

    public function getProcessor(): DispatcherProcessorInterface
    {
        throw new RuntimeException('Not supported.');
    }

    public function create(TraceCreateObject $parameters): void
    {
        $this->creatingTraces[$parameters->traceId] = $parameters;
    }

    public function update(TraceUpdateObject $parameters): void
    {
        $this->updatingTraces[$parameters->traceId] = $parameters;
    }

    /**
     * @return array<string, TraceCreateObject>
     */
    public function getCreatingTraces(): array
    {
        return $this->creatingTraces;
    }

    /**
     * @return array<string, TraceUpdateObject>
     */
    public function getUpdatingTraces(): array
    {
        return $this->updatingTraces;
    }
}
