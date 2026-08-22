<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\DataFormatter;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;

/**
 * Not tested
 *
 * An anonymous notifiable is addressed by the very thing that identifies a person - an
 * address, a phone number - so those are split out of the notifiable's description and
 * carried under `recipients`, where the key list reaches them. Folding them into one
 * `Anonymous:...` string put them somewhere nothing could mask.
 */
class NotificationWatcher implements WatcherInterface
{
    public function __construct(
        protected Processor $processor
    ) {
    }

    public function register(?array $config): void
    {
        $this->processor->registerEvent(NotificationSent::class, [$this, 'handleNotification']);
    }

    public function handleNotification(NotificationSent $event): void
    {
        $notification = get_class($event->notification);

        $data = [
            'notification' => $notification,
            'queued'       => in_array(ShouldQueue::class, class_implements($event->notification)),
            'notifiable'   => $this->formatNotifiable($event->notifiable),
            'channel'      => $event->channel,
            'target'       => [
                'recipients' => $this->getRecipients($event->notifiable),
            ],
            'response' => $event->response,
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

    /**
     * Identifies the notifiable without carrying anything that addresses it: an
     * anonymous notifiable's routes go to getRecipients() instead.
     */
    protected function formatNotifiable(mixed $notifiable): string
    {
        if ($notifiable instanceof Model) {
            return DataFormatter::model($notifiable);
        }

        if ($notifiable instanceof AnonymousNotifiable) {
            return 'Anonymous';
        }

        if (!is_object($notifiable)) {
            return (string) $notifiable;
        }

        return get_class($notifiable);
    }

    /**
     * @return array<string, string>
     */
    protected function getRecipients(mixed $notifiable): array
    {
        if (!$notifiable instanceof AnonymousNotifiable) {
            return [];
        }

        return array_map(
            fn(mixed $route): string => is_array($route)
                ? implode(',', $route)
                : (string) $route,
            $notifiable->routes
        );
    }
}
