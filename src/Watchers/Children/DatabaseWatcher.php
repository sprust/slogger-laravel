<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;

readonly class DatabaseWatcher implements WatcherInterface
{
    public function __construct(
        protected Processor $processor,
        protected TraceDataMasker $masker,
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
            ...$this->describeBindings($event->bindings),
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
     *
     * @param array<int|string, mixed> $bindings
     *
     * @return array<string, mixed>
     */
    protected function describeBindings(array $bindings): array
    {
        return $this->masker->isEnabled()
            ? ['bindings_count' => count($bindings)]
            : ['bindings' => $bindings];
    }
}
