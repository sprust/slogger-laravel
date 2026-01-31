<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Database\Eloquent\Model;
use Throwable;

class DataFormatter
{
    /**
     * @param Throwable $exception
     *
     * @return array{
     *     message: string,
     *     exception: string,
     *     file: string,
     *     line: int,
     *     trace: array<array{file?: string, line?: int}>
     * }
     */
    public static function exception(Throwable $exception): array
    {
        return [
            'message'   => $exception->getMessage(),
            'exception' => get_class($exception),
            'file'      => $exception->getFile(),
            'line'      => $exception->getLine(),
            'trace'     => self::stackTrace($exception->getTrace()),
        ];
    }

    public static function model(Model $model): string
    {
        return $model::class . ':' . $model->getKey();
    }

    /**
     * @param array<array{file?: string, line?: int}> $stackTrace
     *
     * @return array<array{file?: string, line?: int}>
     *
     * @see Throwable::getTrace()
     */
    private static function stackTrace(array $stackTrace): array
    {
        return array_filter(
            array_map(
                static fn(array $item) => array_filter([
                    'file' => $item['file'] ?? null,
                    'line' => $item['line'] ?? null,
                ]),
                $stackTrace
            )
        );
    }
}
