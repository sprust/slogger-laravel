<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceDataComplementer;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class TraceDataComplementerTest extends BaseTestCase
{
    public function testInjectAddsTraceAndAdditionalData(): void
    {
        config()->set('slogger.data_completer.excluded_file_masks', []);

        $complementer = new TraceDataComplementer(
            app: $this->getApp(),
            watchersConfig: new WatchersConfig()
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
            watchersConfig: new WatchersConfig()
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
        config()->set('slogger.data_completer.excluded_file_masks', [__FILE__]);

        $complementer = new TraceDataComplementer(
            app: $this->getApp(),
            watchersConfig: new WatchersConfig()
        );

        $data = [];

        $complementer->inject($data);

        $trace = $data['__trace'] ?? [];

        foreach ($trace as $item) {
            if (isset($item['file'])) {
                self::assertNotSame(__FILE__, $item['file']);
            }
        }
    }
}
