<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Mail\Events\MessageSent;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;
use Symfony\Component\Mime\Address;

/**
 * Addresses are carried as `email`/`full_name` pairs: the masker matches key names,
 * so an address has to sit in a value under a key that says what it is.
 */
class MailWatcher implements WatcherInterface
{
    public function __construct(
        protected Processor $processor
    ) {
    }

    public function register(?array $config): void
    {
        $this->processor->registerEvent(MessageSent::class, [$this, 'handleMessageSent']);
    }

    public function handleMessageSent(MessageSent $event): void
    {
        $data = [
            'mailable' => $this->getMailable($event),
            'queued'   => $this->getQueuedStatus($event),
            'message'  => [
                'from'     => $this->formatAddresses($event->message->getFrom()),
                'reply_to' => $this->formatAddresses($event->message->getReplyTo()),
                'to'       => $this->formatAddresses($event->message->getTo()),
                'cc'       => $this->formatAddresses($event->message->getCc()),
                'bcc'      => $this->formatAddresses($event->message->getBcc()),
                'subject'  => $event->message->getSubject(),
            ],
        ];

        $this->processor->push(
            type: TraceTypeEnum::Mail->value,
            status: TraceStatusEnum::Success->value,
            data: $data
        );
    }

    protected function getMailable(MessageSent $event): string
    {
        return $event->data['__laravel_mailable']
            ?? $event->data['__laravel_notification']
            ?? '';
    }

    protected function getQueuedStatus(MessageSent $event): bool
    {
        return $event->data['__laravel_notification_queued'] ?? false;
    }

    /**
     * Symfony Mime addresses: the `[address => name]` shape came from Swift Mailer,
     * dropped in Laravel 6.
     *
     * @param Address[]|null $addresses
     *
     * @return list<array{email: string, full_name: string}>|null
     */
    protected function formatAddresses(?array $addresses): ?array
    {
        if (is_null($addresses)) {
            return null;
        }

        return array_values(
            array_map(
                static fn(Address $address): array => [
                    'email'     => $address->getAddress(),
                    'full_name' => $address->getName(),
                ],
                $addresses
            )
        );
    }
}
