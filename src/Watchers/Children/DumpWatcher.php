<?php

namespace SLoggerLaravel\Watchers\Children;

use Closure;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;
use Symfony\Component\VarDumper\VarDumper;

class DumpWatcher implements WatcherInterface
{
    /**
     * The handler this watcher installs, kept so it can be put back.
     *
     * Dumping has to happen with the handler removed, or VarDumper would call back
     * into here forever - and what is put back afterwards is this closure, which is
     * all the config was ever kept for.
     *
     * @var (Closure(mixed): void)|null
     */
    protected ?Closure $handler = null;

    public function __construct(
        protected Processor $processor
    ) {
    }

    public function register(?array $config): void
    {
        $this->handler ??= function (mixed $dump): void {
            $this->handleDump($dump);
        };

        VarDumper::setHandler($this->handler);
    }

    public function handleDump(mixed $dump): void
    {
        VarDumper::setHandler(null);

        try {
            VarDumper::dump($dump);
        } finally {
            // put back even when dumping threw: otherwise the handler stays off for
            // the rest of the process and nothing is traced again
            VarDumper::setHandler($this->handler);
        }

        if ($this->processor->isPaused()) {
            return;
        }

        $this->processor->handleWatcher(fn() => $this->onHandleDump($dump));
    }

    /**
     * @return array<int|string, mixed>|scalar|null
     */
    protected static function prepareDump(mixed $dump): mixed
    {
        if (is_scalar($dump) || is_null($dump) || is_array($dump)) {
            return $dump;
        }

        if (!is_object($dump)) {
            return get_debug_type($dump);
        }

        $encoded = json_encode($dump, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $decoded = $encoded === false ? null : json_decode($encoded, true);

        // an object with no public state json_encodes to `{}`; naming its class says
        // more than an empty object does
        return is_array($decoded) && $decoded !== [] ? $decoded : $dump::class;
    }

    protected function onHandleDump(mixed $dump): void
    {
        $data = [
            // not print_r(): flattening an object into a string destroys the very
            // structure the masker walks, so `[password] => hunter2` came out intact
            // where `['password' => 'hunter2']` is masked
            'dump' => self::prepareDump($dump),
        ];

        $this->processor->push(
            type: TraceTypeEnum::Dump->value,
            status: TraceStatusEnum::Success->value,
            data: $data
        );
    }
}
