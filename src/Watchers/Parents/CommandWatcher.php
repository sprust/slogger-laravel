<?php

namespace SLoggerLaravel\Watchers\Parents;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Carbon;
use SLoggerLaravel\Context\TraceContextInterface;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;
use Symfony\Component\Console\Input\InputInterface;

/**
 * @phpstan-type OpenCommand array{trace_id: string, command: string|null, started_at: Carbon}
 */
class CommandWatcher implements WatcherInterface
{
    /**
     * Commands started and not yet finished, outermost first.
     *
     * Per unit of work for the same reason as the requests one: takeCommand()
     * truncates whatever sits above the entry it took.
     *
     * @see getOpenCommands()
     */
    protected const CONTEXT_KEY_COMMANDS = 'slogger.watcher.command.open';
    /**
     * @var string[]
     */
    protected array $exceptedCommands = [];

    public function __construct(
        protected readonly Processor $processor,
        protected readonly TraceContextInterface $context,
    ) {
    }

    public function register(?array $config): void
    {
        // a swept trace never comes back here; its entry would be taken by the next
        // finish, closing the wrong trace
        $this->processor->onTraceInterrupted(
            function (string $traceId): void {
                $this->setOpenCommands(
                    array_values(
                        array_filter(
                            $this->getOpenCommands(),
                            static fn(array $command): bool => $command['trace_id'] !== $traceId
                        )
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
            ...$this->describeInput($input),
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

        // read after the trace was started, not before: starting it dispatches, and
        // dispatching suspends
        $commands = $this->getOpenCommands();

        $commands[] = [
            'trace_id'   => $traceId,
            'command'    => $event->command,
            'started_at' => $loggedAt,
        ];

        $this->setOpenCommands($commands);
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
            ...$this->describeInput($input),
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
     * @return array{arguments: array<string, mixed>, options: array<string, mixed>}
     */
    protected function describeInput(?InputInterface $input): array
    {
        return [
            'arguments' => $input?->getArguments() ?? [],
            'options'   => $input?->getOptions() ?? [],
        ];
    }

    /**
     * This command's own entry, not merely the innermost: a nested command that never
     * finished would be closed in its place. What sits above it the processor sweeps.
     *
     * @return OpenCommand|null
     */
    protected function takeCommand(?string $command): ?array
    {
        $commands = $this->getOpenCommands();

        for ($index = count($commands) - 1; $index >= 0; $index--) {
            if ($commands[$index]['command'] !== $command) {
                continue;
            }

            $found = $commands[$index];

            $this->setOpenCommands(array_slice($commands, 0, $index));

            return $found;
        }

        return null;
    }

    /**
     * @return list<OpenCommand>
     */
    protected function getOpenCommands(): array
    {
        /** @var list<OpenCommand> $commands */
        $commands = $this->context->get(static::CONTEXT_KEY_COMMANDS, []);

        return $commands;
    }

    /**
     * @param list<OpenCommand> $commands
     */
    protected function setOpenCommands(array $commands): void
    {
        $this->context->set(static::CONTEXT_KEY_COMMANDS, $commands);
    }

    /**
     * Both arguments are nullable on Laravel 10 and plain from 11 on. Declaring them
     * nullable here is what keeps the analyser happy on every leg of the matrix.
     */
    protected function makeCommandView(?string $command, ?InputInterface $input): string
    {
        $command ??= $input?->getArguments()['command'] ?? 'unknown';

        if (!is_string($command)) {
            return 'unknown';
        }

        return $command;
    }
}
