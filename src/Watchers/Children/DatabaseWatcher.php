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

    public function register(?array $config): void
    {
        $this->processor->registerEvent(QueryExecuted::class, [$this, 'handleQueryExecuted']);
    }

    public function handleQueryExecuted(QueryExecuted $event): void
    {
        $data = [
            'connection' => $event->connectionName,
            'sql'        => Str::substr($event->sql, 0, 10000),
            'bindings'   => $this->maskBindings($event->bindings),
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

    /**
     * Positional, so no key list can reach them: length is the only signal there is. A
     * short or numeric binding is kept as it was - a PIN or an OTP is not covered here.
     */
    protected function maskBindings(mixed $bindings): mixed
    {
        if (is_string($bindings)) {
            if (Str::length($bindings) > 5) {
                return MaskHelper::maskValue($bindings);
            }

            return $bindings;
        }

        if (is_numeric($bindings)) {
            return $bindings;
        }

        if (is_array($bindings)) {
            $arrayValue = [];

            foreach ($bindings as $valueKey => $valueValue) {
                $arrayValue[$valueKey] = $this->maskBindings($valueValue);
            }

            return $arrayValue;
        }

        return $bindings;
    }
}
