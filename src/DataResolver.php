<?php

namespace SLoggerLaravel;

use Closure;

class DataResolver
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $data = null;

    /**
     * @param Closure(): array<string, mixed> $resolver
     */
    public function __construct(
        private readonly Closure $resolver
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return is_null($this->data)
            ? ($this->data = $this->resolve())
            : $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(): array
    {
        $resolver = $this->resolver;

        return $resolver();
    }
}
