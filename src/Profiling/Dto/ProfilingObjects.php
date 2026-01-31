<?php

namespace SLoggerLaravel\Profiling\Dto;

class ProfilingObjects
{
    /**
     * @var ProfilingObject[]
     */
    private array $items = [];

    public function __construct(private readonly string $mainCaller)
    {
    }

    public function getMainCaller(): string
    {
        return $this->mainCaller;
    }

    public function add(ProfilingObject $object): static
    {
        $this->items[] = $object;

        return $this;
    }

    /**
     * @return ProfilingObject[]
     */
    public function getItems(): array
    {
        return $this->items;
    }
}
