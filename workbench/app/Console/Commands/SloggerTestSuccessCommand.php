<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SloggerTestSuccessCommand extends Command
{
    protected $signature = 'slogger:test-success';

    protected $description = 'SLogger test command: success';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}