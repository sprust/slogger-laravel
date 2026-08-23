<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Gate;

use Closure;
use Illuminate\Support\Facades\Gate;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\GateWatcher;

class GateWatcherTest extends BaseChildWatcherTestCase
{
    protected function getTraceType(): string
    {
        return 'gate';
    }

    protected function getWatcherClass(): string
    {
        return GateWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return static function (): void {
            Gate::define(
                'slogger-test',
                static fn(mixed $user, string $flag): bool => $flag === 'allow'
            );

            Gate::allows('slogger-test', ['allow']);
        };
    }

    protected function assertSuccess(TraceCreateObject $creatingTrace): void
    {
        $data = $creatingTrace->data;

        self::assertSame('slogger-test', $data['ability']);
        self::assertSame('allowed', $data['result']);
        self::assertSame(['allow'], $data['arguments']);
        self::assertNull($data['user_id']);

        self::assertSame(['allowed'], $creatingTrace->tags);
    }
}
