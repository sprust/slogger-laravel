<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Event;

use App\Events\SuccessEvent;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\Children\EventWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class ConfigIgnoreEventTest extends BaseTestCase
{
    public function testIgnoredEventDoesNotCreateTrace(): void
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
