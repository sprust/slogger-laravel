<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NestedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

readonly class NestedEventJob implements ShouldQueue
{
    public function handle(): void
    {
        event(new NestedEvent());
    }
}
