<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Mail\Events\MessageSent;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;
use Symfony\Component\Mime\Address;

/**
 * Not tested
 */
class MailWatcher implements WatcherInterface
{
    public function __construct(
        protected Processor $processor
    ) {
    }

    public function register(): void
    {
        $this->processor->registerEvent(MessageSent::class, [$this, 'handleMessageSent']);
    }

    public function handleMessageSent(MessageSent $event): void
    {
        $data = [
            'mailable' => $this->getMailable($event),
            'queued'   => $this->getQueuedStatus($event),
            'from'     => $this->formatAddresses($event->message->getFrom()),
            'reply_to' => $this->formatAddresses($event->message->getReplyTo()),
            'to'       => $this->formatAddresses($event->message->getTo()),
            'cc'       => $this->formatAddresses($event->message->getCc()),
            'bcc'      => $this->formatAddresses($event->message->getBcc()),
            'subject'  => $event->message->getSubject(),
        ];

        $this->processor->push(
            type: TraceTypeEnum::Mail->value,
            status: TraceStatusEnum::Success->value,
            data: $data
        );
    }

    protected function getMailable(MessageSent $event): string
    {
        return $event->data['__laravel_notification'] ?? '';
    }

    protected function getQueuedStatus(MessageSent $event): bool
    {
        return $event->data['__laravel_notification_queued'] ?? false;
    }

    /**
     * @param array<string, string>|Address[]|null $addresses
     *
     * @return array<string, string>|null
     */
    protected function formatAddresses(?array $addresses): ?array
    {
        if (is_null($addresses)) {
            return null;
        }

        return collect($addresses)
            ->flatMap(function ($address, $key) {
                if ($address instanceof Address) {
                    return [$address->getAddress() => $address->getName()];
                }

                return [$key => $address];
            })
            ->all();
    }
}
