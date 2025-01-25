<?php

namespace SLoggerLaravel\Dispatcher\Items\Transporter\Commands;

use Illuminate\Console\Command;
use SLoggerLaravel\Dispatcher\Items\Transporter\TransporterProcess;

class StatTransporterCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'slogger:transporter:stat';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Transporter statistic';

    public function handle(TransporterProcess $process): int
    {
        return $process->stat();
    }
}
