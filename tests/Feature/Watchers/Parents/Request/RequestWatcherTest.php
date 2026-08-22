<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use App\Events\NestedEvent;
use SLoggerLaravel\Configs\WatchersConfig;
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

    public function testRouteParametersAreCarriedAsDataAndNotAsTags(): void
    {
        $this->get(route('slogger.reset', ['token' => 'tok-secret']))
            ->assertOk();

        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);

        // the pattern, not the value bound to it: a tag is never masked. Both traces
        // carry it - the start trace used to tag the concrete url
        self::assertSame(['/slogger/reset/{token}'], $creating[0]->tags);
        self::assertSame(['/slogger/reset/{token}'], $updating[0]->tags);

        // and `uri` is the pattern too: it sits at the top level of the trace data,
        // which the masker leaves alone by design
        self::assertSame('/slogger/reset/{token}', $creating[0]->data['uri']);
        self::assertSame('/slogger/reset/{token}', ($updating[0]->data ?? [])['uri']);

        $data = $updating[0]->data ?? [];

        self::assertSame(['token' => 'tok-secret'], $data['route_parameters']);

        $masked = app(TraceDataMasker::class)->mask($data);

        self::assertSame(MaskHelper::FULL_MASK, $masked['route_parameters']['token']);

        // nothing anywhere in the trace still carries the bound value
        foreach ([$creating[0]->tags, $updating[0]->tags] as $tags) {
            self::assertStringNotContainsString('tok-secret', implode(' ', $tags));
        }

        self::assertStringNotContainsString('tok-secret', json_encode($masked, JSON_THROW_ON_ERROR));
    }

    public function testTheTraceIdHeaderReachesTheClient(): void
    {
        $response = $this->get(route('slogger.success'))
            ->assertOk();

        $headerKey = app(WatchersConfig::class)->requestsHeaderParentTraceIdKey();

        self::assertNotNull($headerKey);

        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);

        // the response the client gets, not one inspected after a hand-made
        // terminate(): under FPM terminate() runs after the response was sent
        self::assertSame(
            $creating[0]->traceId,
            $response->headers->get($headerKey)
        );
    }

    public function testAnUnroutedPathKeepsOnlyItsFirstSegment(): void
    {
        // routing has not happened when RequestHandling fires, and a 404 never routes
        // at all, so the tag is built from what the caller typed
        $shorten = (new \ReflectionClass(RequestWatcher::class))->getMethod('shortenUnroutedPath');

        // the canonical secret-in-path shapes: a password reset, an email verify
        self::assertSame('/reset/…', $shorten->invoke(null, '/reset/tok-secret'));
        self::assertSame('/verify/…', $shorten->invoke(null, '/verify/abc'));
        self::assertSame('/reset/…', $shorten->invoke(null, '/reset/tok-secret/confirm'));

        // nothing to hide in a single segment
        self::assertSame('/health', $shorten->invoke(null, '/health'));
        self::assertSame('/', $shorten->invoke(null, '/'));
    }

    public function testAnUnroutedRequestKeepsTheTagsItsStartTraceCarried(): void
    {
        $watcher = $this->getApp()->make(RequestWatcher::class);

        $method = (new \ReflectionClass(RequestWatcher::class))->getMethod('getPostTags');

        $tags = $method->invoke(
            $watcher,
            \Illuminate\Http\Request::create('/nowhere/at/all'),
            new \Symfony\Component\HttpFoundation\Response()
        );

        // null leaves them alone; [] would replace them with nothing, and a 404 under
        // global middleware would end up untagged
        self::assertNull($tags);
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
        self::assertSame('/slogger/success', $creatingTrace->data['uri']);
        self::assertSame('GET', $creatingTrace->data['method']);
        self::assertSame(['/slogger/success'], $creatingTrace->tags);

        $data = $updatingTrace->data ?? [];

        self::assertSame(200, $data['response']['status']);
        self::assertSame(['ok' => true], $data['response']['data']);
        self::assertSame([], $data['route_parameters']);

        // the route pattern, never the values bound to it
        self::assertSame(['/slogger/success'], $updatingTrace->tags);
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
        self::assertSame('/slogger/failed', $creatingTrace->data['uri']);

        self::assertSame(500, $updatingTrace->data['response']['status'] ?? null);
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
        // the event was recorded as a child of the request, not as an orphan
        self::assertSame($creatingTrace->traceId, $creatingEventTrace->parentTraceId);
        self::assertSame([NestedEvent::class], $creatingEventTrace->tags);
    }
}
