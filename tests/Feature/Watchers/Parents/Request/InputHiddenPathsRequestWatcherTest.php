<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

class InputHiddenPathsRequestWatcherTest extends BaseWatcherTestCase
{
    public function test(): void
    {
        $this->registerWatcher(
            watcherClass: RequestWatcher::class,
            config: [
                'input' => [
                    'hidden_paths' => [
                        'slogger/success',
                    ],
                ],
            ],
        );

        $this
            ->getJson(
                route(
                    'slogger.success',
                    [
                        'token'    => 'request-token',
                        'password' => 'request-password',
                        'safe'     => 'safe-value',
                    ]
                )
            )
            ->assertOk();

        $trace = $this->getRequestUpdatingTrace();

        self::assertSame(
            [
                '__cleaned' => null,
            ],
            $trace->data['request']['parameters'] ?? null
        );
    }

    private function getRequestUpdatingTrace(): TraceUpdateObject
    {
        $creating = $this->dispatcher->findCreating(
            type: 'request',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(
            traceId: $creating[0]->traceId,
            status: TraceStatusEnum::Success,
        );

        self::assertCount(1, $updating);

        return $updating[0];
    }
}
