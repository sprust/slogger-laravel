<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TraceHelper
{
    public static function makeTraceId(): string
    {
        $prefix = (string) config('slogger.trace_id_prefix');

        if ($prefix === '') {
            $prefix = Str::slug((string) config('app.name'));
        }

        if ($prefix === '') {
            $prefix = 'app';
        }

        return $prefix . '-' . Str::uuid()->toString();
    }

    public static function calcDuration(Carbon $startedAt): float
    {
        return self::roundDuration(
            $startedAt->clone()->setTimezone('UTC')
                ->diffInMicroseconds(now()->setTimezone('UTC')) * 0.000001
        );
    }

    public static function roundDuration(float $duration): float
    {
        return round($duration, 6);
    }
}
