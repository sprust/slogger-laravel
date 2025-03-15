<?php

namespace SLoggerLaravel\Dispatcher;

use Illuminate\Console\Command;
use SLoggerLaravel\Configs\DispatcherConfig;
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
    public function handle(Dispatcher $dispatcher, DispatcherConfig $config): void
    {
        $this->info('Dispatcher starting...');

        $processState = new DispatcherProcessState(
            masterCommandName: $this->getName()
        );

        try {
            $dispatcher->start(
                processState: $processState,
                dispatcher: $this->argument('dispatcher') ?: $config->getDefault()
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
