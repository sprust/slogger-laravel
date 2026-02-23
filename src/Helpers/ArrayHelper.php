<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Support\Str;

class ArrayHelper
{
    /**
     * @param array<int|string, mixed> $array
     */
    public static function findKeyInsensitive(array $array, string $key): int|string|null
    {
        foreach (array_keys($array) as $aKey) {
            if (Str::lower((string) $aKey) === Str::lower($key)) {
                return $aKey;
            }
        }

        return null;
    }
}
