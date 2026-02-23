<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

class InputOnlyPathsRequestWatcherTest extends BaseWatcherTestCase
{
    public function test(): void
    {
        $this->registerWatcher(
            watcherClass: RequestWatcher::class,
            config: [
                'input' => [
                    'only_paths' => [
                        'slogger/success',
                    ],
                ],
            ],
        );

        $this
            ->getJson(
                route('slogger.success', ['token' => 'request-token'])
            )
            ->assertOk();

        $this
            ->getJson(
                route('slogger.sensitive', ['token' => 'request-token'])
            )
            ->assertOk();

        $successTrace  = $this->getUpdatingTraceByTag('/slogger/success');
        $successParams = $successTrace->data['request']['parameters'] ?? [];

        self::assertSame(
            'request-token',
            $successParams['token'] ?? null
        );

        $sensitiveTrace   = $this->getUpdatingTraceByTag('/slogger/sensitive');
        $sensitiveParams  = $sensitiveTrace->data['request']['parameters'] ?? null;
        $sensitiveHeaders = $sensitiveTrace->data['request']['headers'] ?? null;

        self::assertSame(
            ['__cleaned' => null],
            $sensitiveParams
        );
        self::assertSame(
            [],
            $sensitiveHeaders
        );
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
