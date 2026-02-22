<?php

namespace SLoggerLaravel\Watchers\Children;

use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\WatcherInterface;
use Symfony\Component\VarDumper\VarDumper;

class DumpWatcher implements WatcherInterface
{
    /**
     * @var array<string, mixed>|null
     */
    protected ?array $config = [];

    public function __construct(
        protected Processor $processor
    ) {
    }

    public function register(?array $config): void
    {
        $this->config = $config;

        VarDumper::setHandler(function (mixed $dump) {
            $this->handleDump($dump);
        });
    }

    public function handleDump(mixed $dump): void
    {
        VarDumper::setHandler(null);

        VarDumper::dump($dump);

        $this->register($this->config);

        if ($this->processor->isPaused()) {
            return;
        }

        $this->processor->handleWatcher(fn() => $this->onHandleDump($dump));
    }

    protected function onHandleDump(mixed $dump): void
    {
        $data = [
            'dump' => is_object($dump) ? (print_r($dump, true)) : $dump,
        ];

        $this->processor->push(
            type: TraceTypeEnum::Dump->value,
            status: TraceStatusEnum::Success->value,
            data: $data
        );
    }
}
