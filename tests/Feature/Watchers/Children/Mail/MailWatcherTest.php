<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Mail;

use Closure;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\MailWatcher;

class MailWatcherTest extends BaseChildWatcherTestCase
{
    protected function getTraceType(): string
    {
        return 'mail';
    }

    protected function getWatcherClass(): string
    {
        return MailWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return static function (): void {
            config()->set('mail.default', 'array');
            config()->set('mail.mailers.array', ['transport' => 'array']);

            Mail::mailer('array')->raw(
                'Test message',
                static function (Message $message): void {
                    $message->from('from@example.test', 'From Name');
                    $message->replyTo('reply@example.test', 'Reply Name');
                    $message->to('to@example.test', 'To Name');
                    $message->cc('cc@example.test', 'Cc Name');
                    $message->bcc('bcc@example.test', 'Bcc Name');
                    $message->subject('Test subject');
                }
            );
        };
    }

    protected function assertSuccess(TraceCreateObject $creatingTrace, TraceUpdateObject $updatingTrace): void
    {
        // no action
    }
}
