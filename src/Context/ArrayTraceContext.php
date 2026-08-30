<?php

namespace SLoggerLaravel\Context;

/**
 * One array for the whole process: what "the current unit of work" means where units
 * are run one at a time - php-fpm, `artisan`, a `queue:work` worker.
 *
 * @see FiberTraceContext the default, which is this plus a map per running fiber
 */
class ArrayTraceContext implements TraceContextInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values)
            ? $this->values[$key]
            : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }
}
