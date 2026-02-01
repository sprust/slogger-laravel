<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;

readonly class DatabaseWatcher implements WatcherInterface
{
    public function __construct(
        protected Processor $processor,
    ) {
    }

    public function register(): void
    {
        $this->processor->registerEvent(QueryExecuted::class, [$this, 'handleQueryExecuted']);
    }

    public function handleQueryExecuted(QueryExecuted $event): void
    {
        $data = [
            'connection' => $event->connectionName,
            'bindings'   => $this->maskValue($event->bindings),
            'sql'        => Str::substr($event->sql, 0, 10000),
        ];

        $this->processor->push(
            type: TraceTypeEnum::Database->value,
            status: TraceStatusEnum::Success->value,
            tags: [
                $event->connectionName,
                Str::substr($event->sql, 0, 40),
            ],
            data: $data,
            duration: TraceHelper::roundDuration($event->time / 1000)
        );
    }

    protected function maskValue(mixed $value): mixed
    {
        if (is_string($value)) {
            if (Str::length($value) > 5) {
                return MaskHelper::maskValue($value);
            }

            return $value;
        }

        if (is_numeric($value)) {
            return $value;
        }

        if (is_array($value)) {
            $arrayValue = [];

            foreach ($value as $valueKey => $valueValue) {
                $arrayValue[$valueKey] = $this->maskValue($valueValue);
            }

            return $arrayValue;
        }

        return $value;
    }
}
