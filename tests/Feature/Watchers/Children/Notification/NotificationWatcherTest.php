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

        self::assertSame('to***********st', $masked['target']['recipients']['mail']);
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
        self::assertSame(['mail' => 'to@example.test'], $data['target']['recipients']);
        self::assertSame('mail', $data['channel']);

        self::assertSame([TestNotification::class], $creatingTrace->tags);
    }
}
