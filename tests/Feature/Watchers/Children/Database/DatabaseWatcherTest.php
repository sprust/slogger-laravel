<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Database;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SLoggerLaravel\Configs\MaskingConfig;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\DatabaseWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class DatabaseWatcherTest extends BaseChildWatcherTestCase
{
    /**
     * Bindings are positional, so nothing says which is a password and which a page
     * number - and length is no signal either: a PIN is short and numeric.
     */
    public function testBindingsAreNotRecordedWhileMaskingIsOn(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(static function (): void {
            DB::select('SELECT ? as pin, ? as otp', ['0000', 4321]);
        });

        $creating = $this->dispatcher->findCreating(type: 'database');

        self::assertCount(1, $creating);

        $data = $creating[0]->data;

        // masking each left a list of `********` and `0` as long as the query has
        // placeholders: no information, and work paid for on every query
        self::assertArrayNotHasKey('bindings', $data);
        self::assertSame(2, $data['bindings_count']);

        self::assertStringNotContainsString('0000', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testBindingsAreRecordedWhenMaskingIsOff(): void
    {
        $this->getApp()['config']->set('slogger.masking', [
            'full_keys'      => [],
            'partial_keys'   => [],
            'value_patterns' => [],
        ]);

        $masker = new TraceDataMasker(new MaskingConfig());

        self::assertFalse($masker->isEnabled());

        // the same switch as everything else: with masking off, a trace carries what
        // the application actually ran
        $watcher = new DatabaseWatcher($this->processor, $masker);

        $traceId = $this->processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null,
        );

        $watcher->handleQueryExecuted(
            new QueryExecuted('SELECT ? as value', ['plain-value'], 1.0, DB::connection())
        );

        $this->processor->stop(
            traceId: $traceId,
            status: TraceStatusEnum::Success->value,
            tags: null,
            data: null,
            duration: 1.0,
            parentLoggedAt: Carbon::now(),
        );

        $creating = $this->dispatcher->findCreating(type: 'database');

        self::assertCount(1, $creating);

        $data = $creating[0]->data;

        self::assertSame(['plain-value'], $data['bindings']);
        self::assertArrayNotHasKey('bindings_count', $data);
    }

    protected function getTraceType(): string
    {
        return 'database';
    }

    protected function getWatcherClass(): string
    {
        return DatabaseWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return static fn() => event(
            DB::statement('SELECT 1')
        );
    }

    protected function assertSuccess(TraceCreateObject $creatingTrace): void
    {
        $data = $creatingTrace->data;

        self::assertSame('SELECT 1', $data['sql']);
        self::assertSame(0, $data['bindings_count']);
        self::assertSame('sqlite', $data['connection']);

        self::assertSame(['sqlite', 'SELECT 1'], $creatingTrace->tags);
    }
}
