<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

class OutputMaskingRequestWatcherTest extends BaseWatcherTestCase
{
    public function test(): void
    {
        $this->registerWatcher(
            watcherClass: RequestWatcher::class,
            config: [
                'output' => [
                    'headers_masking' => [
                        '*' => [
                            'set-cookie',
                        ],
                    ],
                    'fields_masking' => [
                        '*' => [
                            '*token*',
                            '*password*',
                        ],
                    ],
                ],
            ],
        );

        $this->getJson(route('slogger.sensitive'))
            ->assertOk();

        $trace = $this->getRequestUpdatingTrace();

        $responseData = $trace->data['response'] ?? [];
        $headers      = $responseData['headers'] ?? [];
        $data         = $responseData['data'] ?? [];

        self::assertNotSame('session=response-cookie', $headers['set-cookie'] ?? null);
        self::assertNotSame('response-token', $data['api_token'] ?? null);
        self::assertNotSame('response-password', $data['user_password'] ?? null);
        self::assertTrue($data['ok'] ?? false);
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
