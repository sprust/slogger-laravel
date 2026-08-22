<?php

namespace SLoggerLaravel\Traces;

/**
 * One scope for the whole process: PHP-FPM, `queue:work`, an artisan command.
 *
 * This is the default, and it is exactly what the package did before scopes
 * existed - the state simply lives in one object instead of being spread across
 * the singletons.
 */
class ProcessTraceScopeResolver implements TraceScopeResolverInterface
{
    private ?TraceScope $scope = null;

    public function current(): TraceScope
    {
        return $this->scope ??= new TraceScope();
    }

    public function isConcurrent(): bool
    {
        return false;
    }
}
