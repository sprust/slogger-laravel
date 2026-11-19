<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\LocalProxy\Metrics\Support;

use Closure;
use SLoggerLaravel\LocalProxy\LocalProxySenderStateRead;
use SLoggerLaravel\LocalProxy\LocalProxySenderStateReader;

/**
 * Delegates to the real reader against the real filesystem and lets a test
 * mutate the directory right before the read with the given index - i.e.
 * between the metrics reader's scan and its second state read.
 */
class InterleavingSenderStateReader extends LocalProxySenderStateReader
{
    private int $index = 0;

    /**
     * @param Closure(string): void $beforeRead runs before the read with the
     *                                          given zero-based index
     */
    public function __construct(
        private readonly LocalProxySenderStateReader $inner,
        private readonly Closure $beforeRead,
        private readonly int $beforeReadIndex,
    ) {
    }

    public function read(string $directory): ?LocalProxySenderStateRead
    {
        if ($this->index === $this->beforeReadIndex) {
            ($this->beforeRead)($directory);
        }

        $this->index++;

        return $this->inner->read($directory);
    }
}
