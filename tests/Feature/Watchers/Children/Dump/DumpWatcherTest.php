<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Dump;

use Closure;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\DumpWatcher;
use Symfony\Component\VarDumper\VarDumper;

class DumpWatcherTest extends BaseChildWatcherTestCase
{
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

    protected function assertSuccess(TraceCreateObject $creatingTrace, TraceUpdateObject $updatingTrace): void
    {
        // no action
    }
}
