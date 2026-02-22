<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

class InputMaskingRequestWatcherTest extends BaseWatcherTestCase
{
    public function test(): void
    {
        $this->registerWatcher(
            watcherClass: RequestWatcher::class,
            config: [
                'input' => [
                    'headers_masking' => [
                        '*' => [
                            'authorization',
                            'cookie',
                            'x-xsrf-token',
                        ],
                    ],
                    'parameters_masking' => [
                        '*' => [
                            '*token*',
                            '*password*',
                        ],
                    ],
                ],
            ],
        );

        $this->withHeaders([
            'authorization' => 'Bearer super-secret-token',
            'cookie'        => 'session=abc123',
            'x-xsrf-token'  => 'xsrf-secret',
        ])->getJson(route('slogger.success', [
            'access_token' => 'request-token',
            'password'     => 'request-password',
            'safe'         => 'safe-value',
        ]))->assertOk();

        $trace = $this->getRequestUpdatingTrace();

        $requestData = $trace->data['request'] ?? [];
        $headers     = $requestData['headers'] ?? [];
        $parameters  = $requestData['parameters'] ?? [];

        self::assertNotSame('Bearer super-secret-token', $headers['authorization'] ?? null);
        self::assertNotSame('session=abc123', $headers['cookie'] ?? null);
        self::assertNotSame('xsrf-secret', $headers['x-xsrf-token'] ?? null);

        self::assertNotSame('request-token', $parameters['access_token'] ?? null);
        self::assertNotSame('request-password', $parameters['password'] ?? null);
        self::assertSame('safe-value', $parameters['safe'] ?? null);
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
