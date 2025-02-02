<?php

namespace SLoggerLaravel\Dispatcher\Items\Transporter\Commands;

use Illuminate\Console\Command;
use SLoggerLaravel\Dispatcher\Items\Transporter\TransporterLoader;

class LoadTransporterCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'slogger:transporter:load';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Load transporter';

    public function handle(TransporterLoader $loader): int
    {
        $this->components->task(
            "Downloading transporter [{$loader->getVersion()}]",
            static function () use ($loader) {
                $loader->load();
            }
        );

        return self::SUCCESS;
    }
}
