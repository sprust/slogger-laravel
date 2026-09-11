<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Items;

class FakeQueueJob
{
    public int $releaseCount = 0;
    public int $deleteCount  = 0;

    public ?int $releaseDelay = null;

    public function __construct(private readonly int $attempts)
    {
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function release(int $delay = 0): void
    {
        $this->releaseCount++;

        $this->releaseDelay = $delay;
    }

    public function delete(): void
    {
        $this->deleteCount++;
    }
}
