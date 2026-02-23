<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SloggerTestExceptedCommand extends Command
{
    protected $signature = 'slogger:test-excepted';

    protected $description = 'SLogger test command: excepted';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
