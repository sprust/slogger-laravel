<?php

namespace SLoggerLaravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use SLoggerLaravel\Config;
use SLoggerLaravel\Events\RequestHandling;
use SLoggerLaravel\Traces\TraceIdContainer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\TerminableInterface;

class HttpMiddleware implements TerminableInterface
{
    private bool $enabled;

    private ?TraceIdContainer $traceIdContainer = null;

    private ?string $traceId = null;
    private ?string $headerParentTraceIdKey = null;

    public function __construct()
    {
        $this->enabled = (bool) config('slogger.enabled');
    }

    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->enabled) {
            $parentTraceId = $request->header($this->getHeaderParentTraceIdKey());

            event(
                new RequestHandling(
                    request: $request,
                    parentTraceId: is_array($parentTraceId)
                        ? ($parentTraceId[0] ?? null)
                        : (is_string($parentTraceId) ? $parentTraceId : null)
                )
            );

            $this->traceId = $this->getLoggerTraceIdContainer()->getParentTraceId();
        }

        return $next($request);
    }

    public function terminate(\Symfony\Component\HttpFoundation\Request $request, Response $response): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($headerParentTraceIdKey = $this->getHeaderParentTraceIdKey()) {
            $response->headers->set($headerParentTraceIdKey, $this->traceId);
        }
    }

    private function getHeaderParentTraceIdKey(): ?string
    {
        return $this->headerParentTraceIdKey
            ?: ($this->headerParentTraceIdKey = app(Config::class)->requestsHeaderParentTraceIdKey());
    }

    private function getLoggerTraceIdContainer(): TraceIdContainer
    {
        return $this->traceIdContainer
            ?: ($this->traceIdContainer = app(TraceIdContainer::class));
    }
}
