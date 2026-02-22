<?php

declare(strict_types=1);

namespace Feature\Watchers\Children\Event;

use App\Events\NestedEvent;
use App\Events\SuccessEvent;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Watchers\Children\EventWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class OnlyEventWatcherTest extends BaseWatcherTestCase
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
                'only_events' => [
                    SuccessEvent::class,
                ],
            ],
        );

        dispatch(
            static fn() => event(
                new SuccessEvent()
            )
        );

        dispatch(
            static fn() => event(
                new NestedEvent()
            )
        );

        $creating = $this->dispatcher->findCreating(
            type: 'event',
            status: TraceStatusEnum::Success,
            isParent: false,
        );

        self::assertCount(
            1,
            $creating
        );

        self::assertEquals(
            SuccessEvent::class,
            $creating[0]->tags[0]
        );
    }
}
