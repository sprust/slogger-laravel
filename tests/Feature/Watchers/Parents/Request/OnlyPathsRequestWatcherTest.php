<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

class OnlyPathsRequestWatcherTest extends BaseWatcherTestCase
{
    public function test(): void
    {
        $this->registerWatcher(
            watcherClass: RequestWatcher::class,
            config: [
                'only_paths' => [
                    route('slogger.success', absolute: false),
                ],
            ],
        );

        $this->getJson(route('slogger.success'))
            ->assertOk();

        $this->getJson(route('slogger.sensitive'))
            ->assertOk();

        $creating = $this->dispatcher->findCreating(
            type: 'request',
            status: TraceStatusEnum::Started,
            tag: route('slogger.success', absolute: false),
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );

        $creating = $this->dispatcher->findCreating(
            type: 'request',
            status: TraceStatusEnum::Started,
            tag: route('slogger.sensitive', absolute: false),
            isParent: true,
        );

        self::assertCount(
            0,
            $creating
        );
    }
}
