<?php

declare(strict_types=1);

namespace SLoggerTestEntities\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use SLoggerTestEntities\Events\NestedEvent;

readonly class NestedEventJob implements ShouldQueue
{
    public function handle(): void
    {
        event(new NestedEvent());
    }
}
