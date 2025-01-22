<?php

namespace SLoggerLaravel\Dispatcher;

use Illuminate\Console\Command;

class StopDispatcherCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'slogger:dispatcher:stop';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command for start dispatcher';

    /**
     * Execute the console command.
     */
    public function handle(DispatcherProcessState $processState): void
    {
        $savedPid = $processState->getSavedPid();

        if (!$savedPid) {
            $this->warn('Dispatcher not started');

            return;
        }

        if (!$processState->isPidActive($savedPid, app(StartDispatcherCommand::class)->getName())) {
            $this->warn("Dispatcher already stopped with PID: $savedPid");

            return;
        }

        $processState->sendStopSignal($savedPid);
    }
}
