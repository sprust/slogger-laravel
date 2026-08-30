<?php

namespace SLoggerLaravel\Watchers\Parents;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SLoggerLaravel\Context\TraceContextInterface;
use SLoggerLaravel\DataResolver;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Events\RequestHandling;
use SLoggerLaravel\Helpers\BodyDecoder;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Middleware\HttpMiddleware;
use SLoggerLaravel\Processor;
use SLoggerLaravel\RequestPreparer\RequestDataFormatter;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\Watchers\WatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @see HttpMiddleware - required for a tracing of requests
 *
 * @phpstan-type OpenRequest array{trace_id: string, request_id: int, boot_time: float, started_at: Carbon, logged_at: Carbon}
 */
class RequestWatcher implements WatcherInterface
{
    /**
     * Requests started and not yet handled, outermost first.
     *
     * Per unit of work, not per process: a process handling two requests at once has
     * two of these, and takeRequest() truncates whatever sits above the one it took.
     *
     * @see getOpenRequests()
     */
    protected const CONTEXT_KEY_REQUESTS = 'slogger.watcher.request.open';
    /**
     * How much of a path survives when there is no route to name it. Two segments
     * would keep the token of `/{action}/{token}`.
     */
    private const UNROUTED_PATH_SEGMENTS = 1;

    /**
     * Whether the request entitled to `LARAVEL_START` has already had it. A fact about
     * the process, so a field; claimed with no suspension point in between, so exactly
     * one request gets it.
     *
     * @see claimLaravelStart()
     */
    protected bool $laravelStartSpent = false;

    /**
     * @var string[]
     */
    protected array $onlyPaths = [];
    /**
     * @var string[]
     */
    protected array $exceptedPaths = [];

    /**
     * @var string[]
     */
    protected array $inputOnlyPaths = [];

    /**
     * @var string[]
     */
    protected array $outputOnlyPaths = [];

    protected RequestDataFormatters $formatters;

    protected int $maxResponseBytes = MaskHelper::MAX_READABLE_BYTES;

    protected int $maxRequestBytes = MaskHelper::MAX_READABLE_BYTES;

    public function __construct(
        protected readonly Application $app,
        protected readonly Processor $processor,
        protected readonly TraceContextInterface $context,
    ) {
        $this->formatters = new RequestDataFormatters();
    }

    public function register(?array $config): void
    {
        // a swept trace never comes back here; its entry would be taken by the next
        // finish, closing the wrong trace
        $this->processor->onTraceInterrupted(
            function (string $traceId): void {
                $this->setOpenRequests(
                    array_values(
                        array_filter(
                            $this->getOpenRequests(),
                            static fn(array $request): bool => $request['trace_id'] !== $traceId
                        )
                    )
                );
            }
        );

        $this->parseConfig($config);

        $this->processor->registerEvent(RequestHandling::class, [$this, 'handleRequestHandling']);
        $this->processor->registerEvent(RequestHandled::class, [$this, 'handleRequestHandled']);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function handleRequestHandling(RequestHandling $event): void
    {
        if ($this->onlyPaths && !$this->isRequestByPatterns($event->request, $this->onlyPaths)) {
            return;
        }

        if ($this->isRequestByPatterns($event->request, $this->exceptedPaths)) {
            return;
        }

        $parentTraceId = $event->parentTraceId;

        $loggedAt = Carbon::now();

        $laravelStart = $this->claimLaravelStart();

        // the process spent that time booting for this request, so it is this
        // request's - both the time it took and the point it started from
        $bootTime = is_null($laravelStart)
            ? -1
            : TraceHelper::roundDuration(microtime(true) - $laravelStart);

        $traceId = $this->processor->startAndGetTraceId(
            type: TraceTypeEnum::Request->value,
            tags: $this->getPreTags($event->request),
            data: [
                ...$this->getCommonRequestData($event->request),
                'boot_time' => $bootTime,
                'request'   => [
                    'headers'    => $this->prepareRequestHeaders($event->request),
                    'parameters' => $this->prepareRequestParameters($event->request),
                ],
            ],
            loggedAt: $loggedAt,
            customParentTraceId: $parentTraceId
        );

        // read after the trace was started, not before: starting it dispatches, and
        // dispatching suspends
        $requests = $this->getOpenRequests();

        $requests[] = [
            'trace_id'   => $traceId,
            'request_id' => spl_object_id($event->request),
            'boot_time'  => $bootTime,
            // where the process began, when the process began for this request, so the
            // duration covers the bootstrap. Otherwise the moment this watcher saw the
            // request - taken inside the request's own flow, unlike anything the whole
            // process shares
            'started_at' => is_null($laravelStart)
                ? $loggedAt->clone()
                : new Carbon($laravelStart),
            'logged_at'  => $loggedAt,
        ];

        $this->setOpenRequests($requests);
    }

    public function handleRequestHandled(RequestHandled $event): void
    {
        if ($this->onlyPaths && !$this->isRequestByPatterns($event->request, $this->onlyPaths)) {
            return;
        }

        if ($this->isRequestByPatterns($event->request, $this->exceptedPaths)) {
            return;
        }

        $requestData = $this->takeRequest($event->request);

        if (!$requestData) {
            return;
        }

        $traceId = $requestData['trace_id'];

        /** @var Carbon $startedAt */
        $startedAt = $requestData['started_at'];
        /** @var Carbon $loggedAt */
        $loggedAt = $requestData['logged_at'];

        $request  = $event->request;
        $response = $event->response;

        $data = [
            ...$this->getCommonRequestData($request),
            'boot_time' => $requestData['boot_time'],
            'request'   => [
                'headers'    => $this->prepareRequestHeaders($request),
                'parameters' => $this->prepareRequestParameters($request),
            ],
            'response' => [
                'status'  => $response->getStatusCode(),
                'headers' => $this->prepareResponseHeaders($request, $response),
                'data'    => $this->prepareResponseData($request, $response),
            ],
            ...$this->getAdditionalData(),
        ];

        $this->processor->stop(
            traceId: $traceId,
            status: $response->isSuccessful()
                ? TraceStatusEnum::Success->value
                : TraceStatusEnum::Failed->value,
            tags: $this->getPostTags($request, $response),
            data: $data,
            duration: TraceHelper::calcDuration($startedAt),
            parentLoggedAt: $loggedAt,
        );
    }

    /**
     * By identity: `RequestHandled` fires for every request, `RequestHandling` only
     * for the routed ones, so the top entry was not always this request's.
     *
     * @return OpenRequest|null
     */
    protected function takeRequest(Request $request): ?array
    {
        $requestId = spl_object_id($request);

        $requests = $this->getOpenRequests();

        for ($index = count($requests) - 1; $index >= 0; $index--) {
            if ($requests[$index]['request_id'] !== $requestId) {
                continue;
            }

            $found = $requests[$index];

            $this->setOpenRequests(array_slice($requests, 0, $index));

            return $found;
        }

        return null;
    }

    /**
     * How long the process spent booting, for the one request that boot was for.
     *
     * `LARAVEL_START` marks where the *process* began: under php-fpm that is this
     * request, under a server that boots once and serves for hours it is the worker's
     * start, hours ago. A console-booted process answering HTTP is such a server and
     * never claims it; any other worker is caught by the claim being spendable once.
     */
    protected function claimLaravelStart(): ?float
    {
        if (
            $this->laravelStartSpent
            || $this->app->runningInConsole()
            || !defined('LARAVEL_START')
        ) {
            return null;
        }

        $this->laravelStartSpent = true;

        // via constant(): the entry script defines it, so the analyser never sees it
        return (float) constant('LARAVEL_START');
    }

    /**
     * @return list<OpenRequest>
     */
    protected function getOpenRequests(): array
    {
        /** @var list<OpenRequest> $requests */
        $requests = $this->context->get(static::CONTEXT_KEY_REQUESTS, []);

        return $requests;
    }

    /**
     * @param list<OpenRequest> $requests
     */
    protected function setOpenRequests(array $requests): void
    {
        $this->context->set(static::CONTEXT_KEY_REQUESTS, $requests);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCommonRequestData(Request $request): array
    {
        $url = $this->getUrlPattern($request);

        /**
         * for support for Laravel 10, 12
         *
         * @var Route|object|string|null $route
         */
        $route = $request->route();

        if ($route instanceof Route) {
            $action      = $route->getActionName();
            $middlewares = $route->gatherMiddleware();
        } else {
            $action      = is_string($route) ? $route : null;
            $middlewares = null;
        }

        $queryString = $request->getQueryString();

        return [
            'ip_address'       => $request->ip(),
            'uri'              => $url,
            'method'           => $request->method(),
            'action'           => $action,
            'middlewares'      => $middlewares,
            'query'            => $request->query->all(),
            'query_string'     => $queryString === '' ? null : $queryString,
            'route_parameters' => $this->getRouteParameters($request),
        ];
    }

    /**
     * @return string[]
     */
    protected function getPreTags(Request $request): array
    {
        return [
            $this->getUrlPattern($request),
        ];
    }

    /**
     * The route pattern - `/reset/{token}` - not the url it was matched from: nothing
     * masks a tag, and the values travel as `route_parameters` instead.
     *
     * Falls back to the path when there is no route (a 404, a request that never
     * reached the router).
     */
    protected function getUrlPattern(Request $request): string
    {
        /**
         * for support for Laravel 10, 12
         *
         * @var Route|object|string|null $route
         */
        $route = $request->route();

        if ($route instanceof Route) {
            return $this->prepareUrl($route->uri());
        }

        if (is_string($route) && $route !== '') {
            return $this->prepareUrl($route);
        }

        // routing has not happened yet, so the path is whatever the caller typed,
        // values and all: `/reset/tok-secret` becomes `/reset/…`
        return $this->prepareUrl(
            self::shortenUnroutedPath(str_replace($request->root(), '', $request->url()))
        );
    }

    /**
     * Keeps the first two segments of a path and marks the rest as dropped.
     */
    protected static function shortenUnroutedPath(string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $s): bool => $s !== ''));

        if (count($segments) <= self::UNROUTED_PATH_SEGMENTS) {
            return '/' . implode('/', $segments);
        }

        return '/' . implode('/', array_slice($segments, 0, self::UNROUTED_PATH_SEGMENTS)) . '/…';
    }

    /**
     * Only the route pattern, never the values bound to it: a tag is not masked.
     *
     * @return string[]|null
     */
    protected function getPostTags(Request $request, Response $response): ?array
    {
        /**
         * for support for Laravel 10, 12
         *
         * @var Route|object|string|null $route
         */
        $route = $request->route();

        if (!$route) {
            // null, not []: an empty array would replace the start trace's tags
            return null;
        }

        if (is_string($route)) {
            return [
                $route,
            ];
        }

        if (!$route instanceof Route) {
            return null;
        }

        return [
            $this->prepareUrl($route->uri()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getRouteParameters(Request $request): array
    {
        /**
         * for support for Laravel 10, 12
         *
         * @var Route|object|string|null $route
         */
        $route = $request->route();

        if (!$route instanceof Route) {
            return [];
        }

        return $route->originalParameters();
    }

    protected function prepareUrl(string $url): string
    {
        return '/' . ltrim($url, '/');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAdditionalData(): array
    {
        return [];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function prepareRequestHeaders(Request $request): array
    {
        if ($this->inputOnlyPaths && !$this->isRequestByPatterns($request, $this->inputOnlyPaths)) {
            return [];
        }

        $uri = $this->getRequestPath($request);

        $headers = $request->headers->all();

        foreach ($this->formatters->getItems() as $formatter) {
            $headers = $formatter->prepareRequestHeaders($uri, $headers);
        }

        return $headers;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function prepareRequestParameters(Request $request): array
    {
        if ($this->inputOnlyPaths && !$this->isRequestByPatterns($request, $this->inputOnlyPaths)) {
            return [
                '__cleaned' => null,
            ];
        }

        $uri = $this->getRequestPath($request);

        foreach ($this->formatters->getItems() as $formatter) {
            if ($formatter->hidesRequestParameters($uri)) {
                // before reading anything, which would only be thrown away
                return [
                    '__cleaned' => null,
                ];
            }
        }

        $parameters = $this->getRequestParameters($request);

        foreach ($this->formatters->getItems() as $formatter) {
            $parameters = $formatter->prepareRequestParameters($uri, $parameters);
        }

        return $parameters;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function prepareResponseHeaders(Request $request, Response $response): array
    {
        if ($this->outputOnlyPaths && !$this->isRequestByPatterns($request, $this->outputOnlyPaths)) {
            return [];
        }

        $uri = $this->getRequestPath($request);

        $headers = $response->headers->all();

        foreach ($this->formatters->getItems() as $formatter) {
            $headers = $formatter->prepareResponseHeaders($uri, $headers);
        }

        return $headers;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function prepareResponseData(Request $request, Response $response): array
    {
        if ($this->outputOnlyPaths && !$this->isRequestByPatterns($request, $this->outputOnlyPaths)) {
            return [
                '__cleaned' => null,
            ];
        }

        if ($response instanceof RedirectResponse) {
            return [
                'redirect' => $response->getTargetUrl(),
            ];
        }

        if ($response instanceof IlluminateResponse && $response->getOriginalContent() instanceof View) {
            return [
                'view' => $response->getOriginalContent()->getPath(),
            ];
        }

        $content = $response->getContent();

        $contentType = $response->headers->get('Content-Type');

        // acceptsJson() alone dropped every SOAP and XML-API response
        if ($request->acceptsJson() || BodyDecoder::isXmlContentType($contentType)) {
            $url = $this->getRequestPath($request);

            if ($content === false) {
                return [];
            }

            // before anything reads it: this runs in the application's own request
            if (strlen($content) > $this->maxResponseBytes) {
                return [
                    '__skipped' => 'response_too_large',
                ];
            }

            $dataResolver = new DataResolver(
                fn() => BodyDecoder::decode($content, $contentType)
            );

            foreach ($this->formatters->getItems() as $formatter) {
                $continue = $formatter->prepareResponseData(
                    url: $url,
                    dataResolver: $dataResolver
                );

                if (!$continue) {
                    break;
                }
            }

            return $dataResolver->getData();
        }

        return [];
    }

    /**
     * @param string[] $patterns
     */
    protected function isRequestByPatterns(Request $request, array $patterns): bool
    {
        $path = trim($request->getPathInfo(), '/');

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern, '/');

            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function getRequestParameters(Request $request): array
    {
        // before input() parses anything: a 20 MB body must not be decoded just to
        // be dropped. Multipart is left to the check below - its length is file bytes
        if ($this->declaredBodyTooLarge($request)) {
            return [
                '__skipped' => 'request_too_large',
            ];
        }

        $files = $request->files->all();

        array_walk_recursive($files, function (&$file) {
            if (!$file instanceof UploadedFile) {
                $file = null;

                return;
            }

            $file = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->isFile() ? ($file->getSize() / 1000) . 'KB' : '0',
            ];
        });

        $parameters = array_replace_recursive($request->input(), $files);

        // Laravel leaves XML out of input(). Not conditional on input() being empty:
        // it merges the query bag, and a single `?wsdl` would suppress the body
        $body = $this->readXmlRequestBody($request);

        $parameters = $body ? [...$parameters, ...$body] : $parameters;

        // Content-Length is absent on a chunked request and misleading on a multipart
        // one, so what was actually collected is measured too
        if (self::exceedsBytes($parameters, $this->maxRequestBytes)) {
            return [
                '__skipped' => 'request_too_large',
            ];
        }

        return $parameters;
    }

    /**
     * Whether the request says, before it is read, that it is too big to record.
     */
    protected function declaredBodyTooLarge(Request $request): bool
    {
        $contentType = (string) $request->headers->get('Content-Type');

        if (Str::startsWith(Str::lower($contentType), 'multipart/')) {
            return false;
        }

        $length = $request->headers->get('Content-Length');

        return is_numeric($length) && (int) $length > $this->maxRequestBytes;
    }

    /**
     * Walked rather than encoded - encoding an oversized payload to measure it is the
     * cost the cap exists to avoid - and stopped at the first byte over the limit.
     *
     * @param array<int|string, mixed> $parameters
     */
    protected static function exceedsBytes(array $parameters, int $limit): bool
    {
        $bytes = 0;

        $walk = static function (mixed $value) use (&$walk, &$bytes, $limit): bool {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $bytes += strlen((string) $key);

                    if ($bytes > $limit || $walk($item)) {
                        return true;
                    }
                }

                return false;
            }

            if (is_string($value)) {
                $bytes += strlen($value);
            } else {
                // a number, a bool, a null: nothing that can carry a payload
                $bytes += 8;
            }

            return $bytes > $limit;
        };

        return $walk($parameters);
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function readXmlRequestBody(Request $request): array
    {
        $contentType = $request->headers->get('Content-Type');

        // cheapest check first, then the size, and only then the body itself
        if (!BodyDecoder::isXmlContentType($contentType)) {
            return [];
        }

        $length = $request->headers->get('Content-Length');

        if (is_numeric($length) && (int) $length > $this->maxRequestBytes) {
            return [
                '__skipped' => 'request_too_large',
            ];
        }

        $content = $request->getContent();

        if (strlen($content) > $this->maxRequestBytes) {
            // Content-Length is absent on a chunked request, so the cap has to be
            // re-checked against what was actually read
            return [
                '__skipped' => 'request_too_large',
            ];
        }

        return BodyDecoder::decode($content, $contentType);
    }

    /**
     * @param array<string, mixed>|null $config
     */
    protected function parseConfig(?array $config): void
    {
        if ($config === null) {
            return;
        }

        $this->onlyPaths     = $config['only_paths'] ?? [];
        $this->exceptedPaths = $config['excepted_paths'] ?? [];

        $this->inputOnlyPaths  = $config['input']['only_paths'] ?? [];
        $this->outputOnlyPaths = $config['output']['only_paths'] ?? [];

        /** @var array<string, RequestDataFormatter> $formatterMap */
        $formatterMap = [];

        $inputFullHiding = $config['input']['hidden_paths'] ?? [];

        foreach ($inputFullHiding as $urlPattern) {
            $formatterMap[$urlPattern] ??= new RequestDataFormatter([$urlPattern]);
            $formatterMap[$urlPattern]->setHideAllRequestParameters(true);
        }

        $outputFullHiding = $config['output']['hidden_paths'] ?? [];

        foreach ($outputFullHiding as $urlPattern) {
            $formatterMap[$urlPattern] ??= new RequestDataFormatter([$urlPattern]);
            $formatterMap[$urlPattern]->setHideAllResponseData(true);
        }

        $this->maxRequestBytes  = (int) ($config['input']['max_content_length'] ?? $this->maxRequestBytes);
        $this->maxResponseBytes = (int) ($config['output']['max_content_length'] ?? $this->maxResponseBytes);

        $this->formatters = new RequestDataFormatters();

        foreach ($formatterMap as $formatter) {
            $this->formatters->add($formatter);
        }
    }

    protected function getRequestPath(Request $request): string
    {
        return $request->path();
    }
}
