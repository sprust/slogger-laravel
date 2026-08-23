<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Database;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SLoggerLaravel\Configs\MaskingConfig;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\DatabaseWatcher;

class DatabaseWatcherTest extends BaseChildWatcherTestCase
{
    /**
     * Bindings are positional, so no key list can reach them: length is what decides,
     * and anything not worth reading travels as it was.
     */
    public function testStringBindingsAreMaskedOnceTheyAreLongEnoughToRead(): void
    {
        $data = $this->recordQuery(['plain-value', 'short', 4321, 12.5, null, true]);

        self::assertSame(
            [MaskHelper::FULL_MASK, 'short', 4321, 12.5, null, true],
            $data['bindings']
        );
    }

    public function testNestedBindingsAreWalked(): void
    {
        $data = $this->recordQuery([['deep-secret', 'ok'], ['key' => 'another-secret']]);

        self::assertSame(
            [[MaskHelper::FULL_MASK, 'ok'], ['key' => MaskHelper::FULL_MASK]],
            $data['bindings']
        );
    }

    /**
     * Not the global masking switch: this happens in the application, and the key
     * lists the dispatcher job works from never see a binding.
     */
    public function testBindingsAreMaskedEvenWithMaskingOff(): void
    {
        $this->getApp()['config']->set('slogger.masking', [
            'full_keys'      => [],
            'partial_keys'   => [],
            'value_patterns' => [],
        ]);

        self::assertFalse((new TraceDataMasker(new MaskingConfig()))->isEnabled());

        $data = $this->recordQuery(['plain-value']);

        self::assertSame([MaskHelper::FULL_MASK], $data['bindings']);
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
        self::assertSame([], $data['bindings']);
        self::assertSame('sqlite', $data['connection']);

        self::assertSame(['sqlite', 'SELECT 1'], $creatingTrace->tags);
    }

    /**
     * @param array<int|string, mixed> $bindings
     *
     * @return array<string, mixed>
     */
    private function recordQuery(array $bindings): array
    {
        $watcher = new DatabaseWatcher($this->processor);

        $connection = DB::connection();

        $traceId = $this->processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null,
        );

        $watcher->handleQueryExecuted(
            new QueryExecuted('SELECT ? as value', $bindings, 1.0, $connection)
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

        return $creating[0]->data;
    }
}
