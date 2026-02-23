<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Job;

use App\Jobs\ExceptedJob;
use App\Jobs\SuccessJob;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class ExceptedJobWatcherTest extends BaseWatcherTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher(
            watcherClass: JobWatcher::class,
            config: [
                'excepted' => [
                    ExceptedJob::class,
                ],
            ]
        );
    }

    public function test(): void
    {
        dispatch(new SuccessJob());
        dispatch(new ExceptedJob());

        $creating = $this->dispatcher->findCreating(
            type: 'job',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );
    }
}
