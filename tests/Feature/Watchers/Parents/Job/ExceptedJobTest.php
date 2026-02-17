<?php

declare(strict_types=1);

namespace Feature\Watchers\Parents\Job;

use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\Parents\JobWatcher;
use SLoggerTestEntities\Jobs\ExceptedJob;
use SLoggerTestEntities\Jobs\SuccessJob;

class ExceptedJobTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher(JobWatcher::class);
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