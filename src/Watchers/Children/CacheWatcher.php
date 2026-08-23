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

// TODO: register all cache event

/**
 * A cached value is nested under the cache key it was stored with, so the key becomes
 * part of the path the dispatcher job matches against. Nothing else can reach it:
 * `value` says nothing about what it holds, and a cache key is the only thing that
 * does. `key` is kept at the top level as well, where it stays readable - it is an
 * identifier, and it is already a tag.
 */
readonly class CacheWatcher implements WatcherInterface
{
    public function __construct(
        protected Processor $processor,
    ) {
    }

    public function register(?array $config): void
    {
        $this->processor->registerEvent(CacheHit::class, [$this, 'handleCacheHit']);
        $this->processor->registerEvent(CacheMissed::class, [$this, 'handleCacheMissed']);

        $this->processor->registerEvent(KeyWritten::class, [$this, 'handleKeyWritten']);
        $this->processor->registerEvent(KeyForgotten::class, [$this, 'handleKeyForgotten']);
    }

    public function handleCacheHit(CacheHit $event): void
    {
        $this->pushCache(
            type: 'hit',
            key: $event->key,
            entry: [
                'value' => $this->prepareValue($event->value),
                'tags'  => $event->tags,
            ]
        );
    }

    public function handleCacheMissed(CacheMissed $event): void
    {
        $this->pushCache(
            type: 'missed',
            key: $event->key,
            entry: [
                'tags' => $event->tags,
            ]
        );
    }

    public function handleKeyWritten(KeyWritten $event): void
    {
        $this->pushCache(
            type: 'set',
            key: $event->key,
            entry: [
                'value'      => $this->prepareValue($event->value),
                'tags'       => $event->tags,
                'expiration' => $event->seconds,
            ]
        );
    }

    public function handleKeyForgotten(KeyForgotten $event): void
    {
        $this->pushCache(type: 'forget', key: $event->key);
    }

    /**
     * The four events differ in what they know about the entry and in nothing else.
     *
     * @param array<string, mixed>|null $entry what is known besides the key, nested
     *                                         under it so the key is part of the path
     *                                         the masker matches
     */
    protected function pushCache(string $type, string $key, ?array $entry = null): void
    {
        if ($this->shouldIgnore($key)) {
            return;
        }

        $data = [
            'type' => $type,
            'key'  => $key,
        ];

        if (!is_null($entry)) {
            $data['cache'] = [$key => $entry];
        }

        $this->processor->push(
            type: TraceTypeEnum::Cache->value,
            status: TraceStatusEnum::Success->value,
            tags: [
                $type,
                $key,
            ],
            data: $data
        );
    }

    /**
     * Whatever will survive being written into a trace. Masking is not this watcher's
     * business - the value sits under its cache key, where the key lists reach it.
     */
    protected function prepareValue(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return DataFormatter::model($value);
        }

        if (is_object($value)) {
            return $value::class;
        }

        return $value;
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
