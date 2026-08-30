<?php

namespace SLoggerLaravel\Context;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

readonly class TraceContextFactory
{
    public function __construct(private Application $app)
    {
    }

    /**
     * `fiber` and `array` are the two that ship here; anything else is taken for the
     * class name of a store the application brought itself.
     *
     * @throws BindingResolutionException
     */
    public function create(string $context): TraceContextInterface
    {
        $class = match ($context) {
            'fiber' => FiberTraceContext::class,
            'array' => ArrayTraceContext::class,
            // trimmed: `\App\Store` and `App\Store` are two container keys, and an
            // application that bound its store under one would get a second instance
            default => ltrim($context, '\\'),
        };

        if (!is_a($class, TraceContextInterface::class, allow_string: true)) {
            throw new RuntimeException("Unknown trace context [$context]");
        }

        return $this->app->make($class);
    }
}
