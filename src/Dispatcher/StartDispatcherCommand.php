<?php

namespace SLoggerLaravel\Dispatcher;

use Illuminate\Console\Command;
use Throwable;

class StartDispatcherCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'slogger:dispatcher:start {dispatcher?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command for start dispatcher';

    /**
     * Execute the console command.
     */
    public function handle(DispatcherProcessState $processState, DispatcherFactory $dispatcherFactory): void
    {
        if ($savedPid = $processState->getSavedPid()) {
            if ($processState->isPidActive($savedPid, $this->getName())) {
                $this->error("Dispatcher already started with PID: $savedPid");

                return;
            }
        }

        $currentPid = $processState->getCurrentPid();

        $processState->savePid($currentPid);

        try {
            $dispatcher = $this->argument('dispatcher') ?: config('slogger.dispatchers.default');

            $dispatcherFactory->create($dispatcher)->start($this->output);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
        }

        $processState->purgePid();

        $this->info('Dispatcher stopped');
    }
}
