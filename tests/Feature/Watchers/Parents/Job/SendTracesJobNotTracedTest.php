<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Job;

use SLoggerLaravel\Dispatcher\ApiClients\ApiClientInterface;
use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\SendTracesJob;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class SendTracesJobNotTracedTest extends BaseWatcherTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // no 'excepted' config at all: the exclusion must be hardcoded in the watcher,
        // an app with a stale published config must not trace the trace-sender job
        $this->registerWatcher(
            watcherClass: JobWatcher::class,
            config: null
        );
    }

    public function testSendTracesJobIsNeverTracedEvenWithoutExceptedConfig(): void
    {
        $this->getApp()->instance(
            ApiClientInterface::class,
            new class implements ApiClientInterface {
                public function sendTraces(TracesObject $traces): void
                {
                }
            }
        );

        dispatch(new SendTracesJob(new TracesObject()));

        self::assertCount(
            0,
            $this->dispatcher->findCreating(type: 'job')
        );
    }
}
