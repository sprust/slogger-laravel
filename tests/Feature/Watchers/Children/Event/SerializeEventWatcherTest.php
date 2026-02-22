<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Event;

use App\Events\SerializableEvent;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Watchers\Children\EventWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class SerializeEventWatcherTest extends BaseWatcherTestCase
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
                'serialize_events' => [
                    SerializableEvent::class,
                ],
            ],
        );

        dispatch(
            static fn() => event(
                new SerializableEvent(
                    name: 'alpha',
                    attempt: 7
                )
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

        self::assertSame(
            [
                'name'    => 'alpha',
                'attempt' => 7,
            ],
            $creating[0]->data['payload'] ?? null,
        );
    }
}
