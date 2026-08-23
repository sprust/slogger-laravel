<?php

namespace SLoggerLaravel\Watchers\Parents;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Carbon;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\OpenTraces;
use SLoggerLaravel\Watchers\WatcherInterface;
use Symfony\Component\Console\Input\InputInterface;

class CommandWatcher implements WatcherInterface
{
    /**
     * @var string[]
     */
    protected array $exceptedCommands = [];

    protected OpenTraces $openCommands;

    public function __construct(
        protected readonly Processor $processor,
    ) {
        $this->openCommands = new OpenTraces();
    }

    public function register(?array $config): void
    {
        // a trace closed by the sweep never comes back here, so its entry would sit
        // in the map and be taken by the next finish - which would then close the
        // wrong trace and leave its own open
        $this->processor->onTraceInterrupted(
            function (string $traceId): void {
                $this->openCommands->forget($traceId);
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

        $this->openCommands->open(
            $traceId,
            [
                'command'    => $event->command,
                'started_at' => $loggedAt,
            ]
        );
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

        $command = $event->command;

        // this command's own entry, not merely the innermost one: a nested command
        // that never reported finishing would otherwise be closed in its place
        /** @var array{command: string|null, trace_id: string, started_at: Carbon}|null $commandData */
        $commandData = $this->openCommands->takeInnermost(
            static fn(array $meta): bool => ($meta['command'] ?? null) === $command
        );

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
