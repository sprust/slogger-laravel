<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;

/**
 * Not tested
 */
class ScheduleWatcher implements WatcherInterface
{
    public function __construct(
        protected Processor $processor
    ) {
    }

    public function register(): void
    {
        $this->processor->registerEvent(ScheduledTaskSkipped::class, [$this, 'handleScheduledTaskSkipped']);
        $this->processor->registerEvent(ScheduledTaskStarting::class, [$this, 'handleScheduledTaskStarting']);
        $this->processor->registerEvent(ScheduledTaskFailed::class, [$this, 'handleScheduledTaskFailed']);
        $this->processor->registerEvent(ScheduledTaskFinished::class, [$this, 'handleScheduledTaskFinished']);
    }

    public function handleScheduledTaskSkipped(ScheduledTaskSkipped $event): void
    {
        $this->pushTask($event->task, 'skipped');
    }

    public function handleScheduledTaskStarting(ScheduledTaskStarting $event): void
    {
        $this->pushTask($event->task, 'starting');
    }

    public function handleScheduledTaskFailed(ScheduledTaskFailed $event): void
    {
        $this->pushTask($event->task, 'failed');
    }

    public function handleScheduledTaskFinished(ScheduledTaskFinished $event): void
    {
        $this->pushTask($event->task, 'finished');
    }

    protected function pushTask(Event $task, string $tag): void
    {
        $data = [
            'command'     => $task instanceof CallbackEvent ? 'Closure' : $task->command,
            'description' => $task->description,
            'expression'  => $task->expression,
            'timezone'    => $task->timezone,
            'user'        => $task->user,
            'output'      => $this->getTaskOutput($task),
        ];

        $this->processor->push(
            type: TraceTypeEnum::Schedule->value,
            status: TraceStatusEnum::Success->value,
            tags: [
                $tag,
            ],
            data: $data
        );
    }

    protected function getTaskOutput(Event $event): string
    {
        if (!$event->output
            || $event->output === $event->getDefaultOutput()
            || $event->shouldAppendOutput
            || !file_exists($event->output)
        ) {
            return '';
        }

        $contents = file_get_contents($event->output);

        if ($contents === false) {
            return '';
        }

        return trim($contents);
    }
}
