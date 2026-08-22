<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceDataComplementer;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Traces\TraceScopeResolverInterface;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class TraceDataComplementerTest extends BaseTestCase
{
    public function testInjectAddsTraceAndAdditionalData(): void
    {
        config()->set('slogger.data_completer.excluded_file_masks', []);

        $complementer = new TraceDataComplementer(
            app: $this->getApp(),
            watchersConfig: new WatchersConfig(),
            scopeResolver: $this->getApp()->make(TraceScopeResolverInterface::class)
        );

        $complementer->add('foo', 'bar');
        $complementer->add('calc', fn() => 'ok');

        $data = [];

        $complementer->inject($data);

        self::assertArrayHasKey('__trace', $data);
        self::assertIsArray($data['__trace']);
        self::assertNotEmpty($data['__trace']);

        foreach ($data['__trace'] as $item) {
            self::assertArrayHasKey('line', $item);
            self::assertTrue(isset($item['class']) || isset($item['file']));
        }

        // one level in, not at the top: the top level of a trace's data belongs to
        // the watcher and is never masked, and this is application data
        self::assertSame('bar', $data['__additional']['foo']);
        self::assertSame('ok', $data['__additional']['calc']);
    }

    public function testAdditionalDataIsReachableByTheMasker(): void
    {
        $complementer = new TraceDataComplementer(
            app: $this->getApp(),
            watchersConfig: new WatchersConfig(),
            scopeResolver: $this->getApp()->make(TraceScopeResolverInterface::class)
        );

        $complementer->add('customer_email', 'john.doe@example.com');
        $complementer->add('api_token', 'tok-secret');

        $data = [];

        $complementer->inject($data);

        $masked = app(TraceDataMasker::class)->mask($data);

        self::assertSame('jo****************om', $masked['__additional']['customer_email']);
        self::assertSame(MaskHelper::FULL_MASK, $masked['__additional']['api_token']);
    }

    public function testInjectRespectsExcludedFileMasks(): void
    {
        // a frame is recorded by class where it has one, and every frame from a test
        // method does - so an assertion looking for `file` never ran, and deleting
        // excluded_file_masks support entirely left this test green. Going through a
        // plain function gives a frame that carries `file`, which is what the masks
        // are matched against
        $before = slogger_probe_trace($this->makeComplementer());

        self::assertContains(__FILE__, array_column($before, 'file'), 'this file should be in the trace to begin with');

        // now exclude the file it lives in
        config()->set('slogger.data_completer.excluded_file_masks', [__FILE__]);

        $after = slogger_probe_trace($this->makeComplementer());

        self::assertNotContains(__FILE__, array_column($after, 'file'));
        self::assertNotEmpty($after, 'excluding one file must not empty the whole trace');
    }

    private function makeComplementer(): TraceDataComplementer
    {
        return new TraceDataComplementer(
            app: $this->getApp(),
            watchersConfig: new WatchersConfig(),
            scopeResolver: $this->getApp()->make(TraceScopeResolverInterface::class)
        );
    }
}
