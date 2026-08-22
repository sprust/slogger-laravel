<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\MaskHelper;
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
            'bindings'   => $this->masker->isEnabled()
                ? $this->maskValue($event->bindings)
                : $event->bindings,
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

    /**
     * Bindings are positional: nothing here says which of them is a password and
     * which is a page number, so all of them are masked. Length is not a signal
     * either - a PIN, an OTP and an account number are short and numeric, and those
     * were exactly what a length or a type check used to let through.
     *
     * This is the one masking the traced application still does, because no key list
     * can reach a positional value. It follows the same switch as the rest: with
     * masking off, bindings are recorded as they are.
     */
    protected function maskValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $arrayValue = [];

            foreach ($value as $valueKey => $valueValue) {
                $arrayValue[$valueKey] = $this->maskValue($valueValue);
            }

            return $arrayValue;
        }

        return MaskHelper::maskValue($value);
    }
}
