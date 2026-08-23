<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Event;

use App\Events\SuccessEvent;
use App\Listeners\TestEventListener;
use Closure;
use Illuminate\Events\Dispatcher as EventsDispatcher;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\EventWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class EventWatcherTest extends BaseChildWatcherTestCase
{
    /** `getListeners()` wraps every registration, so every trace said `Closure`. */
    public function testListenersAreRecordedByTheirRealNames(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        $this->getApp()->make(EventsDispatcher::class)
            ->listen(SuccessEvent::class, TestEventListener::class);

        dispatch(static fn() => event(new SuccessEvent()));

        $creating = $this->dispatcher->findCreating(type: 'event');

        self::assertNotEmpty($creating);

        $listeners = $creating[count($creating) - 1]->data['listeners'] ?? [];

        self::assertSame(
            [TestEventListener::class . '@handle'],
            array_column($listeners, 'name')
        );
    }

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

    protected function assertSuccess(TraceCreateObject $creatingTrace): void
    {
        self::assertSame([SuccessEvent::class], $creatingTrace->tags);
        self::assertArrayHasKey('listeners', $creatingTrace->data);
    }
}
