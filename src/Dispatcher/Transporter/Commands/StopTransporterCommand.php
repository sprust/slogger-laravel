<?php

namespace SLoggerLaravel\Dispatcher\Transporter\Commands;

use Illuminate\Console\Command;
use SLoggerLaravel\Dispatcher\Transporter\TransporterProcess;

class StopTransporterCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'slogger:transporter:stop {--env=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Stop transporter';

    public function handle(TransporterProcess $process): int
    {
        return $process->handle('manage stop', $this->option('env'));
    }
}
