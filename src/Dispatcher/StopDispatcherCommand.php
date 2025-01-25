<?php

namespace SLoggerLaravel\Dispatcher;

use Illuminate\Console\Command;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessState;

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
    public function handle(Dispatcher $dispatcher): void
    {
        $processState = new DispatcherProcessState(
            app(StartDispatcherCommand::class)->getName()
        );

        $state = $processState->getSaved();

        if (!$state) {
            $this->warn('Dispatcher not started');

            return;
        }

        $dispatcher->stop($state);
    }
}
