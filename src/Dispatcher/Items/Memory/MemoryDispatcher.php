<?php

namespace SLoggerLaravel\Dispatcher\Items\Memory;

use RuntimeException;
use SLoggerLaravel\Dispatcher\Items\DispatcherProcessorInterface;
use SLoggerLaravel\Dispatcher\Items\TraceDispatcherInterface;
use SLoggerLaravel\Enums\TraceStatusEnum;
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
    public function findCreating(
        ?string $parentTraceId = null,
        ?string $type = null,
        ?TraceStatusEnum $status = null,
        ?string $tag = null,
        ?bool $isParent = null,
    ): array {
        return array_values(
            array_filter(
                $this->creatingTraces,
                fn(TraceCreateObject $trace) => ($parentTraceId === null || $trace->parentTraceId === $parentTraceId)
                    && ($type === null || $trace->type === $type)
                    && ($status === null || $trace->status === $status->value)
                    && ($tag === null || in_array($tag, $trace->tags, true))
                    && ($isParent === null || $trace->isParent === $isParent)
            )
        );
    }

    /**
     * @return list<TraceUpdateObject>
     */
    public function findUpdating(
        ?string $traceId = null,
        ?TraceStatusEnum $status = null,
        ?string $tag = null,
    ): array {
        return array_values(
            array_filter(
                $this->updatingTraces,
                fn(TraceUpdateObject $trace) => ($traceId === null || $trace->traceId === $traceId)
                    && ($status === null || $trace->status === $status->value)
                    && ($tag === null || in_array($tag, $trace->tags ?: [], true))
            )
        );
    }

    public function flush(): void
    {
        $this->creatingTraces = [];
        $this->updatingTraces = [];
    }
}
