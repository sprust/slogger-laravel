<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\DataFormatter;
use SLoggerLaravel\Watchers\AbstractWatcher;

/**
 * Not tested
 */
class NotificationWatcher extends AbstractWatcher
{
    public function register(): void
    {
        $this->listenEvent(NotificationSent::class, [$this, 'handleNotification']);
    }

    public function handleNotification(NotificationSent $event): void
    {
        $this->safeHandleWatching(fn() => $this->onHandleNotification($event));
    }

    protected function onHandleNotification(NotificationSent $event): void
    {
        $notification = get_class($event->notification);

        $data = [
            'notification' => $notification,
            'queued'       => in_array(ShouldQueue::class, class_implements($event->notification)),
            'notifiable'   => $this->formatNotifiable($event->notifiable),
            'channel'      => $event->channel,
            'response'     => $event->response,
        ];

        $this->processor->push(
            type: TraceTypeEnum::Notification->value,
            status: TraceStatusEnum::Success->value,
            tags: [
                $notification,
            ],
            data: $data
        );
    }

    protected function formatNotifiable(mixed $notifiable): string
    {
        if ($notifiable instanceof Model) {
            return DataFormatter::model($notifiable);
        } elseif ($notifiable instanceof AnonymousNotifiable) {
            $routes = array_map(
                fn($route) => is_array($route) ? implode(',', $route) : $route,
                $notifiable->routes
            );

            return 'Anonymous:' . implode(',', $routes);
        }

        if (!is_object($notifiable)) {
            return (string) $notifiable;
        }

        return get_class($notifiable);
    }
}
