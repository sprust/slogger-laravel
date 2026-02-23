<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\NestedEvent;
use Illuminate\Console\Command;

class SloggerTestNestedEventCommand extends Command
{
    protected $signature = 'slogger:test-nested-event';

    protected $description = 'SLogger test command: success + nested event';

    public function handle(): int
    {
        event(new NestedEvent());

        return self::SUCCESS;
    }
}
