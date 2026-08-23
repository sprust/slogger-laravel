<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Notification;

use App\Notifications\TestNotification;
use Closure;
use Illuminate\Support\Facades\Notification;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\NotificationWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class NotificationWatcherTest extends BaseChildWatcherTestCase
{
    public function testRecipientsAreMaskedOnTheirWayOut(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch($this->getSuccessCallback());

        $creating = $this->dispatcher->findCreating(type: 'notification');

        self::assertCount(1, $creating);

        $masked = app(TraceDataMasker::class)->mask($creating[0]->data);

        self::assertSame('to***********st', $masked['recipients']['mail']);
    }

    public function testAnAddressedRouteKeepsTheAddressAndNotOnlyTheName(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(static function (): void {
            config()->set('mail.default', 'array');
            config()->set('mail.mailers.array', ['transport' => 'array']);

            // the documented addressed form: the key is the address, the value only
            // names it. Joining the values shipped `John Doe` and dropped the one
            // thing the notification was actually sent to
            Notification::route('mail', ['to@example.test' => 'John Doe'])->notify(
                new TestNotification()
            );
        });

        $creating = $this->dispatcher->findCreating(type: 'notification');

        self::assertCount(1, $creating);

        self::assertSame(
            [['email' => 'to@example.test', 'full_name' => 'John Doe']],
            $creating[0]->data['recipients']['mail']
        );

        $masked = app(TraceDataMasker::class)->mask($creating[0]->data);

        // and in the shape the mail watcher uses, both halves are reachable
        self::assertSame('to***********st', $masked['recipients']['mail'][0]['email']);
        self::assertSame('Jo****oe', $masked['recipients']['mail'][0]['full_name']);
    }

    public function testAListOfRoutesIsKeptAsAList(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(static function (): void {
            config()->set('mail.default', 'array');
            config()->set('mail.mailers.array', ['transport' => 'array']);

            Notification::route('mail', ['first@example.test', 'second@example.test'])
                ->notify(new TestNotification());
        });

        $creating = $this->dispatcher->findCreating(type: 'notification');

        self::assertCount(1, $creating);

        self::assertSame(
            ['first@example.test', 'second@example.test'],
            $creating[0]->data['recipients']['mail']
        );
    }

    protected function getTraceType(): string
    {
        return 'notification';
    }

    protected function getWatcherClass(): string
    {
        return NotificationWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return static function (): void {
            config()->set('mail.default', 'array');
            config()->set('mail.mailers.array', ['transport' => 'array']);

            Notification::route('mail', 'to@example.test')->notify(
                new TestNotification()
            );
        };
    }

    protected function assertSuccess(TraceCreateObject $creatingTrace): void
    {
        $data = $creatingTrace->data;

        // the notifiable says what it is; what addresses it lives under `recipients`,
        // where the key list reaches it
        self::assertSame('Anonymous', $data['notifiable']);
        self::assertSame(['mail' => 'to@example.test'], $data['recipients']);
        self::assertSame('mail', $data['channel']);

        self::assertSame([TestNotification::class], $creatingTrace->tags);
    }
}
