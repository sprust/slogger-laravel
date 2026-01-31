<?php

declare(strict_types=1);

namespace Feature\Watchers\Parents\Job;

use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class JobWatcherTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher(JobWatcher::class);
    }

    public function test(): void
    {
        dispatch(static fn() => null);

        self::assertCount(
            1,
            $this->dispatcher->getCreatingTraces()
        );

        self::assertCount(
            1,
            $this->dispatcher->getUpdatingTraces()
        );
    }
}
