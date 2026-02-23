<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\HttpClient;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Guzzle\GuzzleHandlerFactory;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\HttpClientWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class HttpClientWatcherTest extends BaseChildWatcherTestCase
{
    public function testParentIsJob(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(
            $this->getSuccessCallback()
        );

        self::assertEquals(
            4,
            $this->dispatcher->totalCount()
        );

        $creating = $this->dispatcher->findCreating(
            type: $this->getTraceType(),
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );

        $creating = $this->dispatcher->findUpdating(
            traceId: $creating[0]->traceId,
            status: TraceStatusEnum::Success,
        );

        self::assertCount(
            1,
            $creating
        );
    }

    protected function getTraceType(): string
    {
        return 'http-client';
    }

    protected function getWatcherClass(): string
    {
        return HttpClientWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return static function (): void {
            $handler = new MockHandler([
                new Response(
                    status: 200,
                    headers: ['Content-Type' => 'application/json'],
                    body: json_encode(['ok' => true], JSON_THROW_ON_ERROR)
                ),
            ]);

            $handlerStack = HandlerStack::create($handler);

            /** @var GuzzleHandlerFactory $factory */
            $factory = app(GuzzleHandlerFactory::class);

            $handlerStack = $factory->prepareHandler(
                formatters: new RequestDataFormatters(),
                handlerStack: $handlerStack
            );

            $client = new Client([
                'handler'     => $handlerStack,
                'http_errors' => false,
            ]);

            $client->request(
                'post',
                'https://example.test/alpha',
                [
                    'json' => [
                        'foo' => 'bar',
                    ],
                ]
            );
        };
    }

    protected function assertSuccess(TraceCreateObject $creatingTrace, TraceUpdateObject $updatingTrace): void
    {
        // no action
    }
}
