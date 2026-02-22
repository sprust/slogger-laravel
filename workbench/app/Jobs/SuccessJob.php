<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

readonly class SuccessJob implements ShouldQueue
{
    public function handle(): void
    {
    }
}
