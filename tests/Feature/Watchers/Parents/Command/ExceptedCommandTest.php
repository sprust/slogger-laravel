<?php

declare(strict_types=1);

namespace Feature\Watchers\Parents\Command;

use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\Parents\CommandWatcher;

class ExceptedCommandTest extends BaseTestCase
{
    public function testExceptedCommandDoesNotCreateTrace(): void
    {
        $this->registerWatcher(CommandWatcher::class);

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