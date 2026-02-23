<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Helpers\TraceDataComplementer;
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

        self::assertSame('bar', $data['foo']);
        self::assertSame('ok', $data['calc']);
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
