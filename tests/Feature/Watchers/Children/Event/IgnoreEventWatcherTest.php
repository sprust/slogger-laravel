<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Event;

use App\Events\SuccessEvent;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Watchers\Children\EventWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class IgnoreEventWatcherTest extends BaseWatcherTestCase
{
    public function test(): void
    {
        $this->registerWatcher(
            watcherClass: JobWatcher::class,
            config: null
        );

        $this->registerWatcher(
            watcherClass: EventWatcher::class,
            config: [
                'ignore_events' => [
                    SuccessEvent::class,
                ],
            ],
        );

        dispatch(
            static fn() => event(
                new SuccessEvent()
            )
        );

        $creating = $this->dispatcher->findCreating(
            type: 'event',
            status: TraceStatusEnum::Success,
            tag: SuccessEvent::class,
            isParent: false,
        );

        self::assertCount(
            0,
            $creating
        );
    }
}
