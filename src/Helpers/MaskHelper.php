<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MaskHelper
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $patterns
     *
     * @return array<string, mixed>
     */
    public static function maskArrayByList(array $data, array $patterns): array
    {
        foreach ($patterns as $key) {
            $realKey = ArrayHelper::findKeyInsensitive($data, $key);

            if (!$realKey) {
                continue;
            }

            $data[$realKey] = self::maskValue($data[$realKey]);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $patterns
     *
     * @return array<string, mixed>
     */
    public static function maskArrayByPatterns(array $data, array $patterns): array
    {
        $result = [];

        $data = Arr::dot($data);

        foreach ($data as $key => $value) {
            if (Str::is($patterns, $key)) {
                $value = self::maskValue($value);
            }

            Arr::set($result, $key, $value);
        }

        return $result;
    }

    public static function maskValue(mixed $value): mixed
    {
        if (!$value) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string) $value;
        }

        if (!is_string($value) && !is_numeric($value) && !is_bool($value)) {
            $value = '********';
        } else {
            $value = (string) $value;

            if (strlen($value) === 1) {
                $value = '*';
            } else {
                $batchLength = (int) ceil(Str::length($value) / 3);

                $value = Str::mask(
                    string: $value,
                    character: '*',
                    index: $batchLength,
                    length: $batchLength
                );
            }
        }

        return $value;
    }
}
