<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Notification;

use App\Notifications\TestNotification;
use Closure;
use Illuminate\Support\Facades\Notification;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\NotificationWatcher;

class NotificationWatcherTest extends BaseChildWatcherTestCase
{
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

    protected function assertSuccess(TraceCreateObject $creatingTrace, TraceUpdateObject $updatingTrace): void
    {
        // no action
    }
}
