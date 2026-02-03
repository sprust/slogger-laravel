<?php

declare(strict_types=1);

namespace SLoggerTestEntities\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

readonly class FailedJob implements ShouldQueue
{
    public function __construct(private string $message)
    {
    }

    public function handle(): void
    {
        throw new RuntimeException($this->message);
    }
}
