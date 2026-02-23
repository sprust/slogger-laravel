<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Command;

use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Watchers\Parents\CommandWatcher;

class ExceptedCommandWatcherTest extends BaseWatcherTestCase
{
    public function test(): void
    {
        $this->registerWatcher(
            watcherClass: CommandWatcher::class,
            config: [
                'excepted' => [
                    'slogger:test-excepted',
                ],
            ]
        );

        $exitCode = $this->artisanCall('slogger:test-success');
        self::assertSame(0, $exitCode);

        $exitCode = $this->artisanCall('slogger:test-excepted');
        self::assertSame(0, $exitCode);

        $creating = $this->dispatcher->findCreating(
            type: 'command',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );
    }
}
