<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\DataFormatter;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;

readonly class CacheWatcher implements WatcherInterface
{
    public function __construct(
        protected Processor $processor,
    ) {
    }

    public function register(): void
    {
        $this->processor->registerEvent(CacheHit::class, [$this, 'handleCacheHit']);
        $this->processor->registerEvent(CacheMissed::class, [$this, 'handleCacheMissed']);

        $this->processor->registerEvent(KeyWritten::class, [$this, 'handleKeyWritten']);
        $this->processor->registerEvent(KeyForgotten::class, [$this, 'handleKeyForgotten']);
    }

    public function handleCacheHit(CacheHit $event): void
    {
        if ($this->shouldIgnore($event->key)) {
            return;
        }

        $type = 'hit';

        $data = [
            'type'  => $type,
            'key'   => $event->key,
            'value' => $this->prepareValue($event->key, $event->value),
            'tags'  => $event->tags,
        ];

        $this->processor->push(
            type: TraceTypeEnum::Cache->value,
            status: TraceStatusEnum::Success->value,
            tags: [
                $type,
                $event->key,
            ],
            data: $data
        );
    }

    public function handleCacheMissed(CacheMissed $event): void
    {
        if ($this->shouldIgnore($event->key)) {
            return;
        }

        $type = 'missed';

        $data = [
            'type' => $type,
            'key'  => $event->key,
            'tags' => $event->tags,
        ];

        $this->processor->push(
            type: TraceTypeEnum::Cache->value,
            status: TraceStatusEnum::Success->value,
            tags: [
                $type,
                $event->key,
            ],
            data: $data
        );
    }

    public function handleKeyWritten(KeyWritten $event): void
    {
        if ($this->shouldIgnore($event->key)) {
            return;
        }

        $type = 'set';

        $data = [
            'type'       => $type,
            'key'        => $event->key,
            'value'      => $this->prepareValue($event->key, $event->value),
            'tags'       => $event->tags,
            'expiration' => $event->seconds,
        ];

        $this->processor->push(
            type: TraceTypeEnum::Cache->value,
            status: TraceStatusEnum::Success->value,
            tags: [
                $type,
                $event->key,
            ],
            data: $data
        );
    }

    public function handleKeyForgotten(KeyForgotten $event): void
    {
        if ($this->shouldIgnore($event->key)) {
            return;
        }

        $type = 'forget';

        $data = [
            'type' => $type,
            'key'  => $event->key,
        ];

        $this->processor->push(
            type: TraceTypeEnum::Cache->value,
            status: TraceStatusEnum::Success->value,
            tags: [
                $type,
                $event->key,
            ],
            data: $data
        );
    }

    protected function prepareValue(string $key, mixed $value): mixed
    {
        if ($this->shouldHideValue($key)) {
            return '********';
        }

        if ($value instanceof Model) {
            return DataFormatter::model($value);
        }

        if (is_object($value)) {
            return $value::class;
        }

        return $value;
    }

    protected function shouldHideValue(string $key): bool
    {
        return false;
    }

    protected function shouldIgnore(string $key): bool
    {
        return Str::is(
            [
                'illuminate:queue:restart',
                'framework/schedule*',
            ],
            $key
        );
    }
}
