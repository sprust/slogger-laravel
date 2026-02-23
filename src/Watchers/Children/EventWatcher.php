<?php

namespace SLoggerLaravel\Watchers\Children;

use Closure;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use ReflectionFunction;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;

/**
 * Not tested on custom events
 */
class EventWatcher implements WatcherInterface
{
    /**
     * @var string[]
     */
    protected array $onlyEvents = [];

    /**
     * @var string[]
     */
    protected array $ignoreEvents = [];

    /**
     * @var string[]
     */
    protected array $serializeEvents = [];

    /**
     * @var string[]
     */
    protected array $possibleOrphans = [];

    public function __construct(
        protected Dispatcher $dispatcher,
        protected Processor $processor,
    ) {
    }

    public function register(?array $config): void
    {
        if ($config !== null) {
            $this->onlyEvents      = $config['only_events'] ?? [];
            $this->ignoreEvents    = $config['ignore_events'] ?? [];
            $this->serializeEvents = $config['serialize_events'] ?? [];
            $this->possibleOrphans = $config['can_be_orphan'] ?? [];
        }

        $this->processor->registerEvent('*', [$this, 'handleEvent']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleEvent(string $eventName, array $payload = []): void
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
            $encodedEvent = json_encode($event);

            if ($encodedEvent === false) {
                return [];
            }

            return json_decode($encodedEvent, true) ?: [];
        }

        return [];
    }

    /**
     * @return array<array{name: string, queued: bool}>
     */
    protected function formatListeners(string $eventName): array
    {
        $listeners = $this->dispatcher->getListeners($eventName);

        return collect($listeners)
            ->map(
                static function ($listener) {
                    if (is_object($listener)) {
                        return get_class($listener);
                    }

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

                    return 'unknown';
                }
            )
            ->map(
                static function ($listener) {
                    $queued = false;

                    if (Str::contains($listener, '@')) {
                        $classImplements = class_implements(Str::beforeLast($listener, '@'));

                        if ($classImplements !== false) {
                            $queued = in_array(ShouldQueue::class, $classImplements);
                        }
                    }

                    return [
                        'name'   => $listener,
                        'queued' => $queued,
                    ];
                }
            )
            ->values()
            ->toArray();
    }

    protected function shouldIgnore(string $eventName): bool
    {
        if ($this->onlyEvents) {
            return !Str::is($this->onlyEvents, $eventName);
        }

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
