<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Log\Events\MessageLogged;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\DataFormatter;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;
use Throwable;

class LogWatcher implements WatcherInterface
{
    public function __construct(
        protected Processor $processor
    ) {
    }

    public function register(): void
    {
        $this->processor->registerEvent(MessageLogged::class, [$this, 'handleMessageLogged']);
    }

    public function handleMessageLogged(MessageLogged $event): void
    {
        $exception = $event->context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $event->context['exception'] = DataFormatter::exception($exception);
        }

        $data = [
            'level'   => $event->level,
            'message' => $event->message,
            'context' => $event->context,
        ];

        $this->processor->push(
            type: TraceTypeEnum::Log->value,
            status: TraceStatusEnum::Success->value,
            tags: [
                $event->level,
            ],
            data: $data
        );
    }
}
