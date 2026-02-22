<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TraceHelper
{
    private static ?string $prefix = null;

    public static function makeTraceId(): string
    {
        return self::getPrefix() . '-' . Str::uuid()->toString();
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

    private static function getPrefix(): string
    {
        if (self::$prefix === null) {
            $prefix = (string) config('slogger.trace_id_prefix');

            if ($prefix === '') {
                $prefix = Str::slug((string) config('app.name'));
            }

            if ($prefix === '') {
                $prefix = 'app';
            }

            self::$prefix = $prefix;
        }

        return self::$prefix;
    }
}
