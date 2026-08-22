<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Database;

use Closure;
use Illuminate\Support\Facades\DB;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\DatabaseWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class DatabaseWatcherTest extends BaseChildWatcherTestCase
{
    /**
     * The only masking left in the traced application: bindings are positional, so
     * the global key list cannot reach them.
     */
    public function testBindingsAreMaskedWhereTheyAreRecorded(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(static function (): void {
            DB::select('SELECT ? as value', ['secret-value']);
        });

        $creating = $this->dispatcher->findCreating(type: 'database');

        self::assertCount(1, $creating);

        self::assertSame(
            [MaskHelper::FULL_MASK],
            $creating[0]->data['bindings'] ?? null
        );
    }

    /**
     * Nothing here says which binding is a password and which is a page number, and
     * length is not a signal either: a PIN, an OTP and an account number are short
     * and numeric, and those were exactly what a length or a type check let through.
     */
    public function testShortAndNumericBindingsAreMaskedToo(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(static function (): void {
            DB::select('SELECT ? as pin, ? as otp', ['0000', 4321]);
        });

        $creating = $this->dispatcher->findCreating(type: 'database');

        self::assertCount(1, $creating);

        self::assertSame(
            [MaskHelper::FULL_MASK, 0],
            $creating[0]->data['bindings'] ?? null
        );
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

    protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace
    ): void {
        // no action
    }
}
