<?php

namespace SLoggerLaravel\Helpers;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;

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

    /**
     * @var array<string, mixed>
     */
    private array $additional = [];

    public function __construct(private readonly Application $app)
    {
        $this->basePathVendor    = base_path('vendor' . DIRECTORY_SEPARATOR);
        $this->basePathPackages  = base_path('packages' . DIRECTORY_SEPARATOR);
        $this->excludedClasses   = [
            self::class,
            static::class,
        ];
        $this->excludedFileMasks = config('slogger.data_completer.excluded_file_masks') ?: [];
    }

    public function add(string $key, mixed $value): void
    {
        $this->additional[$key] = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function inject(array &$data): void
    {
        $backTrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

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

            if (Str::startsWith($file, $this->basePathVendor)
                || Str::startsWith($file, $this->basePathPackages)
            ) {
                continue;
            }

            $trace[] = [
                'file' => $file,
                'line' => $line,
                ...($class ? ['class' => $class] : []),
            ];
        }

        $data['__trace'] = $trace;

        foreach ($this->additional as $key => $value) {
            if ($value instanceof Closure) {
                $value = $this->app->call($value);
            }

            $data[$key] = $value;
        }
    }
}
