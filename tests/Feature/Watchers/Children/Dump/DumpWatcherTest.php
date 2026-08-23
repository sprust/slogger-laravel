<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Dump;

use Closure;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\DumpWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;
use Symfony\Component\VarDumper\VarDumper;

class DumpWatcherTest extends BaseChildWatcherTestCase
{
    public function testAnObjectKeepsTheStructureTheMaskerWalks(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(static function (): void {
            VarDumper::dump(new DumpedPayload());
        });

        $creating = $this->dispatcher->findCreating(type: 'dump');

        self::assertCount(1, $creating);

        // print_r() flattened it into a string, and a string has no keys for the
        // masker to match against
        self::assertSame(
            ['api_token' => 'sk-live-SECRET', 'page' => 2],
            $creating[0]->data['dump']
        );

        $masked = app(TraceDataMasker::class)->mask($creating[0]->data);

        self::assertSame(MaskHelper::FULL_MASK, $masked['dump']['api_token']);
        self::assertSame(2, $masked['dump']['page']);
    }

    protected function getTraceType(): string
    {
        return 'dump';
    }

    protected function getWatcherClass(): string
    {
        return DumpWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return static fn() => VarDumper::dump('');
    }

    protected function assertSuccess(TraceCreateObject $creatingTrace): void
    {
        // `__trace` is injected by the data complementer, so assert the field itself
        self::assertSame('', $creatingTrace->data['dump']);
    }
}

class DumpedPayload
{
    public string $api_token = 'sk-live-SECRET';

    public int $page = 2;
}
