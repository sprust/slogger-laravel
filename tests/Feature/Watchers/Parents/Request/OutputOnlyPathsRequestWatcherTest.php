<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

class OutputOnlyPathsRequestWatcherTest extends BaseWatcherTestCase
{
    public function test(): void
    {
        $this->registerWatcher(
            watcherClass: RequestWatcher::class,
            config: [
                'output' => [
                    'only_paths' => [
                        'slogger/sensitive',
                    ],
                ],
            ],
        );

        $this->getJson(route('slogger.success'))
            ->assertOk();

        $this->getJson(route('slogger.sensitive'))
            ->assertOk();

        $successTrace   = $this->getUpdatingTraceByTag('/slogger/success');
        $successData    = $successTrace->data['response']['data'] ?? null;
        $successHeaders = $successTrace->data['response']['headers'] ?? null;

        self::assertSame(['__cleaned' => null], $successData);
        self::assertSame([], $successHeaders);

        $sensitiveTrace = $this->getUpdatingTraceByTag('/slogger/sensitive');
        $sensitiveData  = $sensitiveTrace->data['response']['data'] ?? [];

        self::assertTrue($sensitiveData['ok'] ?? false);
    }

    private function getUpdatingTraceByTag(string $tag): TraceUpdateObject
    {
        $updating = $this->dispatcher->findUpdating(
            status: TraceStatusEnum::Success,
            tag: $tag,
        );

        self::assertCount(1, $updating);

        return $updating[0];
    }
}
