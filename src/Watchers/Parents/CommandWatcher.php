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
     * @var string[]
     */
    protected array $exceptedCommands = [];

    /**
     * Commands started and not yet finished, outermost first.
     *
     * @var list<array{trace_id: string, command: string|null, started_at: Carbon}>
     */
    protected array $commands = [];

    public function __construct(
        protected readonly Processor $processor,
    ) {
    }

    public function register(?array $config): void
    {
        // a swept trace never comes back here; its entry would be taken by the next
        // finish, closing the wrong trace
        $this->processor->onTraceInterrupted(
            function (string $traceId): void {
                $this->commands = array_values(
                    array_filter(
                        $this->commands,
                        static fn(array $command): bool => $command['trace_id'] !== $traceId
                    )
                );
            }
        );

        if ($config !== null) {
            $this->exceptedCommands = $config['excepted'] ?? [];
        }

        $this->processor->registerEvent(CommandStarting::class, [$this, 'handleCommandStarting']);
        $this->processor->registerEvent(CommandFinished::class, [$this, 'handleCommandFinished']);
    }

    public function handleCommandStarting(CommandStarting $event): void
    {
        if (in_array($event->command, $this->exceptedCommands, strict: true)) {
            return;
        }

        $input = $event->input;

        $data = [
            'command' => $this->makeCommandView(
                command: $event->command,
                input: $input
            ),
            'arguments' => $input->getArguments(),
            'options'   => $input->getOptions(),
        ];

        $loggedAt = Carbon::now();

        $traceId = $this->processor->startAndGetTraceId(
            type: TraceTypeEnum::Command->value,
            tags: [
                $this->makeCommandView(
                    command: $event->command,
                    input: $input
                ),
            ],
            data: $data,
            loggedAt: $loggedAt,
            customParentTraceId: null,
        );

        $this->commands[] = [
            'trace_id'   => $traceId,
            'command'    => $event->command,
            'started_at' => $loggedAt,
        ];
    }

    // already wrapped by registerEvent(): the watcher firewall is not something
    // this has to arrange for itself
    public function handleCommandFinished(CommandFinished $event): void
    {
        // the start of an excepted command is not traced, so its finish must not take
        // the entry of the command that is running it
        if (in_array($event->command, $this->exceptedCommands, strict: true)) {
            return;
        }

        $commandData = $this->takeCommand($event->command);

        if (!$commandData) {
            return;
        }

        $traceId = $commandData['trace_id'];

        /** @var Carbon $startedAt */
        $startedAt = $commandData['started_at'];

        $input = $event->input;

        $data = [
            'command' => $this->makeCommandView(
                command: $event->command,
                input: $input
            ),
            'exit_code' => $event->exitCode,
            'arguments' => $input->getArguments(),
            'options'   => $input->getOptions(),
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

    /**
     * This command's own entry, not merely the innermost: a nested command that never
     * finished would be closed in its place. What sits above it the processor sweeps.
     *
     * @return array{trace_id: string, command: string|null, started_at: Carbon}|null
     */
    protected function takeCommand(?string $command): ?array
    {
        for ($index = count($this->commands) - 1; $index >= 0; $index--) {
            if ($this->commands[$index]['command'] !== $command) {
                continue;
            }

            $found = $this->commands[$index];

            $this->commands = array_slice($this->commands, 0, $index);

            return $found;
        }

        return null;
    }

    /**
     * `$command` is nullable on Laravel 10 and a plain string from 11 on, so the
     * fallback stays: a command with no name of its own is a real shape there.
     */
    protected function makeCommandView(?string $command, InputInterface $input): string
    {
        $command ??= $input->getArguments()['command'] ?? 'unknown';

        if (!is_string($command)) {
            return 'unknown';
        }

        return $command;
    }
}
