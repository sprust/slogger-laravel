<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SLoggerTestEntities\Events\NestedEvent;

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