<?php

namespace SLoggerLaravel\Watchers\Parents;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Carbon;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Watchers\AbstractWatcher;
use Symfony\Component\Console\Input\InputInterface;

class CommandWatcher extends AbstractWatcher
{
    /**
     * @var array<array{trace_id: string, started_at: Carbon}>
     */
    protected array $commands = [];
    /**
     * @var string[]
     */
    protected array $exceptedCommands = [];

    protected function init(): void
    {
        $this->exceptedCommands = $this->loggerConfig->commandsExcepted();
    }

    public function register(): void
    {
        $this->listenEvent(CommandStarting::class, [$this, 'handleCommandStarting']);
        $this->listenEvent(CommandFinished::class, [$this, 'handleCommandFinished']);
    }

    public function handleCommandStarting(CommandStarting $event): void
    {
        $this->safeHandleWatching(fn() => $this->onHandleCommandStarting($event));
    }

    protected function onHandleCommandStarting(CommandStarting $event): void
    {
        if (in_array($event->command, $this->exceptedCommands)) {
            return;
        }

        $data = [
            'command'   => $this->makeCommandView($event->command, $event->input),
            'arguments' => $event->input->getArguments(),
            'options'   => $event->input->getOptions(),
        ];

        $loggedAt = now();

        $traceId = $this->processor->startAndGetTraceId(
            type: TraceTypeEnum::Command->value,
            tags: [
                $this->makeCommandView($event->command, $event->input),
            ],
            data: $data,
            loggedAt: $loggedAt,
            customParentTraceId: null,
        );

        $this->commands[] = [
            'trace_id'   => $traceId,
            'started_at' => $loggedAt,
        ];
    }

    public function handleCommandFinished(CommandFinished $event): void
    {
        $this->safeHandleWatching(fn() => $this->onHandleCommandFinished($event));
    }

    protected function onHandleCommandFinished(CommandFinished $event): void
    {
        $commandData = array_pop($this->commands);

        if (!$commandData) {
            return;
        }

        $traceId = $commandData['trace_id'];

        /** @var Carbon $startedAt */
        $startedAt = $commandData['started_at'];

        $data = [
            'command'   => $this->makeCommandView($event->command, $event->input),
            'exit_code' => $event->exitCode,
            'arguments' => $event->input->getArguments(),
            'options'   => $event->input->getOptions(),
        ];

        $this->processor->stop(
            traceId: $traceId,
            status: $event->exitCode
                ? TraceStatusEnum::Failed->value
                : TraceStatusEnum::Success->value,
            tags: null,
            data: $data,
            duration: TraceHelper::calcDuration($startedAt),
            parentLoggedAt: $startedAt,
        );
    }

    protected function makeCommandView(?string $command, InputInterface $input): string
    {
        $command = $command ?? $input->getArguments()['command'] ?? 'default';

        if (!is_string($command)) {
            return 'default';
        }

        return $command;
    }
}
