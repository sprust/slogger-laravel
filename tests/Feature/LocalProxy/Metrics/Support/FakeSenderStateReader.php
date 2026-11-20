<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\LocalProxy\Metrics\Support;

use Closure;
use SLoggerLaravel\LocalProxy\LocalProxySenderStateRead;
use SLoggerLaravel\LocalProxy\LocalProxySenderStateReader;

class FakeSenderStateReader extends LocalProxySenderStateReader
{
    private int $index = 0;

    /**
     * @param list<LocalProxySenderStateRead|null> $reads  reads returned in
     *                                                     order; the last one repeats
     * @param Closure(int, string): void|null      $onRead runs before each read,
     *                                                     with the zero-based read index
     */
    public function __construct(
        private readonly array $reads,
        private readonly ?Closure $onRead = null,
    ) {
    }

    public function read(string $directory): ?LocalProxySenderStateRead
    {
        if ($this->onRead !== null) {
            ($this->onRead)($this->index, $directory);
        }

        if ($this->reads === []) {
            return null;
        }

        $read = $this->reads[min($this->index, count($this->reads) - 1)];

        $this->index++;

        return $read;
    }
}
