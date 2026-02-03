<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Job;

use SLoggerLaravel\Tests\Feature\Watchers\Parents\BaseParentTestCase;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

abstract class BaseJobWatcherTestCase extends BaseParentTestCase
{
    protected function getTraceType(): string
    {
        return 'job';
    }

    protected function getWatcherClass(): string
    {
        return JobWatcher::class;
    }
}
