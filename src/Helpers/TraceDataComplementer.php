<?php

namespace SLoggerLaravel\Helpers;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use SLoggerLaravel\Configs\WatchersConfig;

class TraceDataComplementer
{
    /** One level in, which is where the dispatcher job's key list reaches them. */
    public const ADDITIONAL_KEY = '__add';

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

    /**
     * Callbacks registered for the whole process, evaluated per trace: one in a
     * service provider has to survive the first job of a `queue:work` worker.
     *
     * @var array<string, Closure>
     */
    private array $providers = [];

    /**
     * Per unit, not per process: `user_id`, `tenant`, `request_id` - what a request
     * has and the next one does not.
     *
     * @var array<string, mixed>
     */
    private array $additional = [];

    public function __construct(
        private readonly Application $app,
        WatchersConfig $watchersConfig
    ) {
        $this->basePathVendor    = base_path('vendor' . DIRECTORY_SEPARATOR);
        $this->basePathPackages  = base_path('packages' . DIRECTORY_SEPARATOR);
        $this->excludedClasses   = [self::class, static::class];
        $this->excludedFileMasks = $watchersConfig->getDataCompleterExcludedFileMasks();
        $this->maxDepth          = 30;
    }

    /**
     * Adds a value to every trace of the current unit of work, under `__add`.
     *
     * A callback is a rule - `fn() => auth()->id()` - registered once for the process.
     * A value is this unit's own and is dropped when the unit ends.
     */
    public function add(string $key, mixed $value): void
    {
        if ($value instanceof Closure) {
            $this->providers[$key] = $value;

            // a value left under this key earlier would otherwise shadow the
            // callback that has just replaced it
            unset($this->additional[$key]);

            return;
        }

        $this->additional[$key] = $value;
    }

    /** @see Processor::stop() */
    public function endUnitOfWork(): void
    {
        $this->additional = [];
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

        // this unit's own values win over the process-wide rules
        $configured = [
            ...$this->providers,
            ...$this->additional,
        ];

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

        $data[self::ADDITIONAL_KEY] = $additional;
    }
}
