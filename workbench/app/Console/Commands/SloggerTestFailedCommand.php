<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SloggerTestFailedCommand extends Command
{
    protected $signature = 'slogger:test-failed';

    protected $description = 'SLogger test command: failed';

    public function handle(): int
    {
        return self::FAILURE;
    }
}
