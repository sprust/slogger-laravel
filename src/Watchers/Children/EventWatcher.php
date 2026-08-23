<?php

namespace SLoggerLaravel\Watchers\Children;

use Closure;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
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
     * Raw, not `getListeners()`: that wraps every entry in a closure, so every trace
     * recorded the same useless `Closure`.
     *
     * @return array<array{name: string, queued: bool}>
     */
    protected function formatListeners(string $eventName): array
    {
        $listeners = $this->dispatcher->getRawListeners()[$eventName] ?? [];

        $names = [];

        foreach ($listeners as $listener) {
            $names[] = self::formatListenerName($listener);
        }

        return array_map(
            static function (string $name): array {
                $class = Str::contains($name, '@') ? Str::beforeLast($name, '@') : $name;

                $implements = class_exists($class) ? class_implements($class) : false;

                return [
                    'name'   => $name,
                    'queued' => $implements !== false && in_array(ShouldQueue::class, $implements),
                ];
            },
            $names
        );
    }

    protected static function formatListenerName(mixed $listener): string
    {
        if (is_string($listener)) {
            return Str::contains($listener, '@') ? $listener : $listener . '@handle';
        }

        if (is_array($listener) && count($listener) === 2) {
            $target = is_object($listener[0]) ? get_class($listener[0]) : $listener[0];

            return is_string($target) && is_string($listener[1])
                ? $target . '@' . $listener[1]
                : 'unknown';
        }

        if ($listener instanceof Closure) {
            return 'Closure';
        }

        if (is_object($listener)) {
            return get_class($listener) . (method_exists($listener, '__invoke') ? '@__invoke' : '');
        }

        return 'unknown';
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
