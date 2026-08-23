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
            'queued'       => $event->notification instanceof ShouldQueue,
            'notifiable'   => $this->formatNotifiable($event->notifiable),
            'channel'      => $event->channel,
            'recipients'   => $this->getRecipients($event->notifiable),
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
     * What the notification was actually sent to, by channel.
     *
     * @return array<string, mixed>
     */
    protected function getRecipients(mixed $notifiable): array
    {
        if (!$notifiable instanceof AnonymousNotifiable) {
            return [];
        }

        return array_map(
            fn(mixed $route): mixed => $this->formatRoute($route),
            $notifiable->routes
        );
    }

    /**
     * A route comes in three documented shapes and only two of them are a plain
     * address: `route('mail', 'a@x.test')`, `route('mail', ['a@x.test', 'b@y.test'])`
     * and `route('mail', ['a@x.test' => 'Name'])`. Joining the values of the last one
     * kept the name and dropped the address - the one thing worth recording - so an
     * addressed route is carried as the `email`/`full_name` pair the mail watcher
     * uses, where both the key list and the address pattern reach it.
     */
    protected function formatRoute(mixed $route): mixed
    {
        if (!is_array($route)) {
            return (string) $route;
        }

        $formatted = [];

        foreach ($route as $key => $value) {
            $formatted[] = is_string($key)
                ? ['email' => $key, 'full_name' => (string) $value]
                : (string) $value;
        }

        return $formatted;
    }
}
