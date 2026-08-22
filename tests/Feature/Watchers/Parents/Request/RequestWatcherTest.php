<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Parents\BaseParentWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

class RequestWatcherTest extends BaseParentWatcherTestCase
{
    public function testTheQueryStringIsCarriedAsDataAndNotAsAUrl(): void
    {
        $this->get(route('slogger.success') . '?page=2&api_token=tok-secret')
            ->assertOk();

        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);

        $data = $creating[0]->data;

        // nothing masks a url, and it is a tag as well - so the query string is split
        // off and carried where the dispatcher job can reach it
        self::assertStringNotContainsString('tok-secret', $data['uri']);
        self::assertStringNotContainsString('tok-secret', implode(' ', $creating[0]->tags));

        self::assertSame('tok-secret', $data['query']['api_token']);

        // Symfony normalises a query string, so assert on what it decodes to
        $raw = [];

        parse_str($data['query_string'], $raw);

        self::assertSame(['api_token' => 'tok-secret', 'page' => '2'], $raw);

        $masked = app(TraceDataMasker::class)->mask($data);

        $parameters = [];

        parse_str($masked['query_string'], $parameters);

        self::assertSame('2', $parameters['page']);
        self::assertSame(MaskHelper::FULL_MASK, $parameters['api_token']);
        self::assertSame(MaskHelper::FULL_MASK, $masked['query']['api_token']);
    }

    protected function getTraceType(): string
    {
        return 'request';
    }

    protected function getWatcherClass(): string
    {
        return RequestWatcher::class;
    }

    protected function runSuccess(): void
    {
        $this->get(route('slogger.success'))
            ->assertOk();
    }

    protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace
    ): void {
        // no action
    }

    protected function runFailed(): void
    {
        $this->get(route('slogger.failed'))
            ->assertInternalServerError();
    }

    protected function assertFailed(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace
    ): void {
        // no action
    }

    protected function runWithNestedEvent(): void
    {
        $this->runSuccess();
    }

    protected function assertWithNestedEvent(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
        TraceCreateObject $creatingEventTrace
    ): void {
        // no action
    }
}
