<?php

namespace SLoggerLaravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Events\RequestHandling;
use SLoggerLaravel\Traces\TraceIdContainer;
use Symfony\Component\HttpFoundation\Response;

class HttpMiddleware
{
    private bool $enabled;

    private ?TraceIdContainer $traceIdContainer = null;

    private ?string $headerParentTraceIdKey = null;

    public function __construct(GeneralConfig $config)
    {
        $this->enabled = $config->isEnabled();
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
        }

        $response = $next($request);

        $this->setTraceIdHeader($response);

        return $response;
    }

    /**
     * On the response the middleware returns, not in terminate(): under FPM
     * terminate() runs after the response has already been sent, so a header set
     * there never reached the client. Cross-service correlation only ever worked in
     * tests, which inspect the response after calling terminate() by hand.
     */
    private function setTraceIdHeader(Response $response): void
    {
        if (!$this->enabled) {
            return;
        }

        $headerParentTraceIdKey = $this->getHeaderParentTraceIdKey();

        if (!$headerParentTraceIdKey) {
            return;
        }

        // read it now rather than remembering it from before $next(): the middleware
        // is a singleton, and a remembered id would belong to whichever request wrote
        // it last
        $traceId = $this->getTraceIdContainer()->getParentTraceId();

        if (is_null($traceId)) {
            return;
        }

        $response->headers->set($headerParentTraceIdKey, $traceId);
    }

    private function getHeaderParentTraceIdKey(): ?string
    {
        return $this->headerParentTraceIdKey ??= app(WatchersConfig::class)->requestsHeaderParentTraceIdKey();
    }

    private function getTraceIdContainer(): TraceIdContainer
    {
        return $this->traceIdContainer ??= app(TraceIdContainer::class);
    }
}
