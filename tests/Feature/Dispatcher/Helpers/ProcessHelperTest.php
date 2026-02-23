<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Helpers;

use SLoggerLaravel\Dispatcher\ProcessHelper;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class ProcessHelperTest extends BaseTestCase
{
    public function testGetCurrentPidReturnsPositive(): void
    {
        $helper = new ProcessHelper();

        self::assertGreaterThan(0, $helper->getCurrentPid());
    }

    public function testIsPidActiveReturnsFalseForInvalidPid(): void
    {
        $helper = new ProcessHelper();

        self::assertFalse($helper->isPidActive(0, 'php'));
        self::assertFalse($helper->isPidActive(-1, 'php'));
    }

    public function testIsPidActiveReturnsTrueForCurrentPid(): void
    {
        $helper = new ProcessHelper();

        $pid = $helper->getCurrentPid();

        self::assertTrue($helper->isPidActive($pid, 'php'));
    }
}
