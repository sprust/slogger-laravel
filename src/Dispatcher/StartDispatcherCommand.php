<?php

namespace SLoggerLaravel\Dispatcher;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessState;
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
    protected $description = 'Start dispatcher';


    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(Dispatcher $dispatcher): void
    {
        if (!config('slogger.enabled')) {
            // If the slogger is disabled, we will just sleep forever

            $logger = Log::channel(config('slogger.log_channel'));

            /**
             * @phpstan-ignore-next-line
             *
             * While loop condition is always true.
             */
            while (true) {
                $message = 'SLogger is disabled';

                $logger->warning($message);
                $this->warn($message);

                sleep(10);
            }
        }

        $this->info('Dispatcher starting...');

        $processState = new DispatcherProcessState(
            masterCommandName: $this->getName()
        );

        try {
            $dispatcher->start(
                processState: $processState,
                dispatcher: $this->argument('dispatcher') ?: config('slogger.dispatchers.default')
            );
        } catch (Throwable $exception) {
            $state = $processState->getSaved();

            if ($state) {
                $dispatcher->stop($state);
            }

            throw $exception;
        }

        $this->info('Dispatcher stopped');
    }
}
