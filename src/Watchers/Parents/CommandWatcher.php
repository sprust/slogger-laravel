<?php

namespace SLoggerLaravel\Watchers\Parents;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Carbon;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;
use Symfony\Component\Console\Input\InputInterface;

class CommandWatcher implements WatcherInterface
{
    /**
     * @var array<array{trace_id: string, started_at: Carbon}>
     */
    protected array $commands = [];
    /**
     * @var string[]
     */
    protected array $exceptedCommands = [];

    public function __construct(
        protected readonly Processor $processor,
    ) {
    }

    public function register(?array $config): void
    {
        if ($config !== null) {
            $this->exceptedCommands = $config['excepted'] ?? [];
        }

        $this->processor->registerEvent(CommandStarting::class, [$this, 'handleCommandStarting']);
        $this->processor->registerEvent(CommandFinished::class, [$this, 'handleCommandFinished']);
    }

    public function handleCommandStarting(CommandStarting $event): void
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
        $this->processor->handleWatcher(fn() => $this->onHandleCommandFinished($event));
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
