<?php

namespace SLoggerLaravel\Watchers\Children;

use Closure;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use ReflectionFunction;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Watchers\AbstractWatcher;

/**
 * Not tested on custom events
 */
class EventWatcher extends AbstractWatcher
{
    /**
     * @var string[]
     */
    private array $ignoreEvents = [];

    /**
     * @var string[]
     */
    private array $serializeEvents = [];

    /**
     * @var string[]
     */
    private array $possibleOrphans = [];

    /**
     * @throws BindingResolutionException
     */
    protected function init(): void
    {
        parent::init();

        $config = $this->app->make(WatchersConfig::class);

        $this->ignoreEvents    = $config->eventsIgnoreEvents();
        $this->serializeEvents = $config->eventsSerializeEvents();
        $this->possibleOrphans = $config->eventsCanBeOrphan();
    }

    public function register(): void
    {
        $this->listenEvent('*', [$this, 'handleEvent']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleEvent(string $eventName, array $payload): void
    {
        $this->safeHandleWatching(fn() => $this->onHandleEvent($eventName, $payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function onHandleEvent(string $eventName, array $payload): void
    {
        if ($this->shouldIgnore($eventName)) {
            return;
        }

        $this->processor->push(
            type: TraceTypeEnum::Event->value,
            status: TraceStatusEnum::Success->value,
            tags: $this->prepareTags($eventName, $payload),
            data: $this->prepareData($eventName, $payload),
            canBeOrphan: $this->canByOrphan($eventName),
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string[]
     */
    protected function prepareTags(string $eventName, array $payload): array
    {
        return [
            $eventName,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function prepareData(string $eventName, array $payload): array
    {
        $payloadData = $this->preparePayload($payload);

        $isBroadcast = class_exists($eventName)
            && in_array(ShouldBroadcast::class, (array) class_implements($eventName));

        return [
            'name'      => $eventName,
            'listeners' => $this->formatListeners($eventName),
            'broadcast' => $isBroadcast,
            ...($payloadData
                ? [
                    'payload' => $payloadData,
                ]
                : []),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function preparePayload(array $payload): array
    {
        if (!$payload) {
            return [];
        }

        $payloadKeys = array_keys($payload);

        $event = $payload[$payloadKeys[0]] ?? null;

        if (is_object($event) && in_array(get_class($event), $this->serializeEvents)) {
            return json_decode(json_encode($event), true) ?: [];
        }

        return [];
    }

    /**
     * @return array<array{name: string, queued: bool}>
     */
    protected function formatListeners(string $eventName): array
    {
        /** @var array<Closure|string|object> $listeners */
        $listeners = $this->app['events']->getListeners($eventName);

        return collect($listeners)
            ->map(function ($listener) {
                $listener = (new ReflectionFunction($listener))
                    ->getStaticVariables()['listener'];

                if (is_string($listener)) {
                    return Str::contains($listener, '@') ? $listener : $listener . '@handle';
                } elseif (is_array($listener) && is_string($listener[0])) {
                    return $listener[0] . '@' . $listener[1];
                } elseif (is_array($listener) && is_object($listener[0])) {
                    return get_class($listener[0]) . '@' . $listener[1];
                } elseif (is_object($listener) && is_callable($listener) && !$listener instanceof Closure) {
                    return get_class($listener) . '@__invoke';
                }

                return 'closure';
            })
            ->map(function ($listener) {
                if (Str::contains($listener, '@')) {
                    $queued = in_array(ShouldQueue::class, class_implements(Str::beforeLast($listener, '@')));
                }

                return [
                    'name'   => $listener,
                    'queued' => $queued ?? false,
                ];
            })
            ->values()
            ->toArray();
    }

    protected function shouldIgnore(string $eventName): bool
    {
        return Str::is(
            [
                'Illuminate\*',
                'Laravel\*',
                'eloquent*',
                'bootstrapped*',
                'bootstrapping*',
                'creating*',
                'composing*',
                'SLoggerLaravel\*',
                ...$this->ignoreEvents,
            ],
            $eventName
        );
    }

    protected function canByOrphan(string $eventName): bool
    {
        return Str::is(
            $this->possibleOrphans,
            $eventName
        );
    }
}
