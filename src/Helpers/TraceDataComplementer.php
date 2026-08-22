<?php

namespace SLoggerLaravel\Helpers;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Traces\TraceScopeResolverInterface;

class TraceDataComplementer
{
    private readonly string $basePathVendor;
    private readonly string $basePathPackages;

    /**
     * @var string[]
     */
    private readonly array $excludedClasses;

    /**
     * @var string[]
     */
    private readonly array $excludedFileMasks;

    private readonly int $maxDepth;

    public function __construct(
        private readonly Application $app,
        WatchersConfig $watchersConfig,
        private readonly TraceScopeResolverInterface $scopeResolver
    ) {
        $this->basePathVendor    = base_path('vendor' . DIRECTORY_SEPARATOR);
        $this->basePathPackages  = base_path('packages' . DIRECTORY_SEPARATOR);
        $this->excludedClasses   = [self::class, static::class];
        $this->excludedFileMasks = $watchersConfig->getDataCompleterExcludedFileMasks();
        $this->maxDepth          = 30;
    }

    /**
     * Adds a value to every trace this process records. It lands under
     * `__additional`, one level in, which is where the dispatcher job's key list can
     * reach it - the top level of a trace's data belongs to the watcher and is never
     * masked, and this is application data.
     */
    public function add(string $key, mixed $value): void
    {
        $this->scopeResolver->current()->additional[$key] = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function inject(array &$data): void
    {
        $backTrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->maxDepth);

        $trace = [];

        foreach ($backTrace as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (is_null($file) || is_null($line)) {
                continue;
            }

            if (Str::is($this->excludedFileMasks, $file)) {
                continue;
            }

            $class = $frame['class'] ?? null;

            $hasClass = is_string($class) && class_exists($class);

            if ($hasClass) {
                $class = trim($class, '\\');

                if (in_array($class, $this->excludedClasses)) {
                    continue;
                }
            }

            if (
                Str::startsWith($file, $this->basePathVendor)
                || Str::startsWith($file, $this->basePathPackages)
            ) {
                continue;
            }

            $trace[] = [
                ...($class ? ['class' => $class] : ['file' => $file]),
                'line' => $line,
            ];
        }

        $data['__trace'] = $trace;

        $configured = $this->scopeResolver->current()->additional;

        if (!$configured) {
            return;
        }

        $additional = [];

        foreach ($configured as $key => $value) {
            if ($value instanceof Closure) {
                $value = $this->app->call($value);
            }

            $additional[$key] = $value;
        }

        $data['__additional'] = $additional;
    }
}
