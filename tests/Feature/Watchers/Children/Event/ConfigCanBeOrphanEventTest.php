<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Event;

use App\Events\NestedEvent;
use App\Events\SuccessEvent;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\Children\EventWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class ConfigCanBeOrphanEventTest extends BaseTestCase
{
    public function test(): void
    {
        $this->registerWatcher(
            watcherClass: EventWatcher::class,
            config: [
                'can_be_orphan' => [
                    SuccessEvent::class,
                ],
            ],
        );

        event(new NestedEvent());
        event(new SuccessEvent());

        $successEvents = $this->dispatcher->findCreating(
            type: 'event',
            status: TraceStatusEnum::Success,
            tag: SuccessEvent::class,
            isParent: false,
        );

        self::assertCount(
            1,
            $successEvents
        );

        $creating = $this->dispatcher->findCreating(
            type: 'event',
            status: TraceStatusEnum::Success,
            isParent: false,
        );

        self::assertCount(1, $creating);

        self::assertEquals(
            SuccessEvent::class,
            $creating[0]->tags[0]
        );
    }
}
