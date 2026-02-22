<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Event;

use App\Events\SuccessEvent;
use Closure;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildTestCase;
use SLoggerLaravel\Watchers\Children\EventWatcher;

class ChildEventTest extends BaseChildTestCase
{
    protected function getTraceType(): string
    {
        return 'event';
    }

    protected function getWatcherClass(): string
    {
        return EventWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return static fn() => event(new SuccessEvent());
    }

    protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace
    ): void {
        // no action
    }
}
