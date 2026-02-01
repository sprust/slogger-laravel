<?php

declare(strict_types=1);

namespace Feature\Watchers\Parents\Job;

use RuntimeException;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\Parents\JobWatcher;
use Throwable;

class JobWatcherTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher(JobWatcher::class);
    }

    public function testSuccess(): void
    {
        dispatch(static fn() => null);

        $this->assertParentTraces(isSuccess: true);
    }

    public function testException(): void
    {
        $message = uniqid();

        $exception = null;

        try {
            dispatch(static fn() => throw new RuntimeException($message));
        } catch (Throwable $exception) {
            //
        }

        self::assertNotNull($exception);

        $this->assertParentTraces(isSuccess: false);
    }
}
