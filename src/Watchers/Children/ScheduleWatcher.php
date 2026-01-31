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
use SLoggerLaravel\Watchers\AbstractWatcher;

/**
 * Not tested
 */
class ScheduleWatcher extends AbstractWatcher
{
    public function register(): void
    {
        $this->listenEvent(ScheduledTaskSkipped::class, [$this, 'handleScheduledTaskSkipped']);
        $this->listenEvent(ScheduledTaskStarting::class, [$this, 'handleScheduledTaskStarting']);
        $this->listenEvent(ScheduledTaskFailed::class, [$this, 'handleScheduledTaskFailed']);
        $this->listenEvent(ScheduledTaskFinished::class, [$this, 'handleScheduledTaskFinished']);
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
        $this->safeHandleWatching(fn() => $this->onPushTask($task, $tag));
    }

    protected function onPushTask(Event $task, string $tag): void
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
