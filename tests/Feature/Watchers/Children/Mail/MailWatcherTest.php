<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Mail;

use Closure;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\MailWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class MailWatcherTest extends BaseChildWatcherTestCase
{
    public function testAddressesAreMaskedOnTheirWayOut(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch($this->getSuccessCallback());

        $creating = $this->dispatcher->findCreating(type: 'mail');

        self::assertCount(1, $creating);

        $masked = app(TraceDataMasker::class)->mask($creating[0]->data);

        self::assertSame('to***********st', $masked['message']['to'][0]['email']);
        self::assertSame('To***me', $masked['message']['to'][0]['full_name']);

        // not a person: left readable
        self::assertSame('Test subject', $masked['message']['subject']);
    }

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

    protected function assertSuccess(TraceCreateObject $creatingTrace): void
    {
        $message = $creatingTrace->data['message'];

        // addresses sit in values under keys that name them, not in the key position
        // where no key list could ever reach them
        self::assertSame(
            [['email' => 'to@example.test', 'full_name' => 'To Name']],
            $message['to']
        );
        self::assertSame(
            [['email' => 'from@example.test', 'full_name' => 'From Name']],
            $message['from']
        );
        self::assertSame(
            [['email' => 'cc@example.test', 'full_name' => 'Cc Name']],
            $message['cc']
        );
        self::assertSame(
            [['email' => 'bcc@example.test', 'full_name' => 'Bcc Name']],
            $message['bcc']
        );

        self::assertSame('Test subject', $message['subject']);
        self::assertFalse($creatingTrace->data['queued']);
    }
}
