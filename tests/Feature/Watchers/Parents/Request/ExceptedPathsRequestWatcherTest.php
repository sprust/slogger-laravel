<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

class ExceptedPathsRequestWatcherTest extends BaseWatcherTestCase
{
    public function test(): void
    {
        $this->registerWatcher(
            watcherClass: RequestWatcher::class,
            config: [
                'excepted_paths' => [
                    'slogger/success',
                ],
            ],
        );

        $this->getJson(route('slogger.success'))
            ->assertOk();

        $creating = $this->dispatcher->findCreating(
            type: 'request',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            0,
            $creating
        );
    }
}
