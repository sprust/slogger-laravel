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
 * What addresses an anonymous notifiable is what identifies a person, so it is split
 * out into `recipients` where the key list reaches it - not left in one string.
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
     * `route('mail', ['a@x.test' => 'Name'])` is documented too, and joining its
     * values kept the name and dropped the address. Carried as the mail watcher's pair.
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
