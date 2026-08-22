<?php

namespace SLoggerLaravel\Watchers\Parents;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SLoggerLaravel\DataResolver;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Events\RequestHandling;
use SLoggerLaravel\Helpers\BodyDecoder;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Middleware\HttpMiddleware;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Traces\TraceScopeResolverInterface;
use SLoggerLaravel\RequestPreparer\RequestDataFormatter;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\Watchers\WatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @see HttpMiddleware - required for a tracing of requests
 */
class RequestWatcher implements WatcherInterface
{
    /**
     * How much of a path survives when there is no route to name it. One: the
     * canonical secret-in-path shape is `/{action}/{token}` - a password reset, an
     * email verification, an invite - so keeping two segments keeps the token.
     */
    private const UNROUTED_PATH_SEGMENTS = 1;

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

    protected int $maxResponseBytes = 1048576;

    protected int $maxRequestBytes = 1048576;

    public function __construct(
        protected readonly Application $app,
        protected readonly Processor $processor,
        protected readonly TraceScopeResolverInterface $scopeResolver,
    ) {
        $this->formatters = new RequestDataFormatters();
    }

    public function register(?array $config): void
    {
        // a trace closed by the sweep never comes back here, so its entry would sit
        // in the stack and be popped by the next finish - which would then close the
        // wrong trace and leave its own open
        $this->processor->onTraceInterrupted(
            function (string $traceId): void {
                $this->scopeResolver->current()->forgetWatcherItemsFor($this, $traceId);
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

        $bootTime = defined('LARAVEL_START')
            ? TraceHelper::roundDuration((microtime(true) - LARAVEL_START))
            : -1;

        if (defined('LARAVEL_START')) {
            $startedAt = new Carbon(LARAVEL_START);
        } else {
            $startedAt = $this->app->get(Kernel::class)->requestStartedAt();
        }

        $startedAt = $startedAt?->clone() ?? Carbon::now();

        $loggedAt = Carbon::now();

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

        // the open requests live in the trace scope, not on the watcher: under a
        // concurrent runtime every request is its own coroutine, and two of them
        // sharing one stack would pop each other's entries
        $this->scopeResolver->current()->pushWatcherItem(
            $this,
            [
                'trace_id'   => $traceId,
                'boot_time'  => $bootTime,
                'started_at' => $startedAt,
                'logged_at'  => $loggedAt,
            ]
        );
    }

    public function handleRequestHandled(RequestHandled $event): void
    {
        if ($this->onlyPaths && !$this->isRequestByPatterns($event->request, $this->onlyPaths)) {
            return;
        }

        if ($this->isRequestByPatterns($event->request, $this->exceptedPaths)) {
            return;
        }

        /** @var array{trace_id: string, boot_time: float, started_at: Carbon, logged_at: Carbon}|null $requestData */
        $requestData = $this->scopeResolver->current()->popWatcherItem($this);

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
     * The route pattern - `/reset/{token}` - not the url it was matched from.
     *
     * A url is carried as a tag and as `uri`, and nothing masks either of those:
     * `/reset/tok-secret` would hand the receiver the token in the clear. The values
     * travel as data instead, in `route_parameters`, where the key list reaches them
     * by parameter name.
     *
     * Falls back to the path when there is no route to speak of (a 404, a request
     * that never reached the router). Nothing better exists in that case, and there
     * are no bound parameters to leak either - only whatever the caller typed.
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

        // routing has not happened yet - the middleware fires this before $next(), and
        // a 404 never routes at all - so the path is whatever the caller typed, values
        // and all. Keep enough of it to tell endpoints apart and drop the rest:
        // `/reset/tok-secret` becomes `/reset/…`, which is the difference between a
        // useful tag and a leaked token
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
     * Only the route pattern, never the values bound to it. A tag is not masked, and
     * `/reset/{token}` binds the token itself - the values travel as data instead, in
     * `route_parameters`, where the key list reaches them by parameter name.
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
            // null, not []: an empty array replaces the tags the start trace carried,
            // and a 404 traced under global middleware ended up with none at all
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
                // before reading anything: the body would be read, and an XML one
                // parsed, only to be replaced by this. The response side is lazy
                // through DataResolver; this side had no such excuse
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

        // an XML response is recorded too, and the client asking for XML rather than
        // JSON is exactly when it arrives: acceptsJson() alone dropped every SOAP and
        // XML-API response on the floor
        if ($request->acceptsJson() || BodyDecoder::isXmlContentType($contentType)) {
            $url = $this->getRequestPath($request);

            if ($content === false) {
                return [];
            }

            // before anything reads or parses it: this runs in the traced
            // application's own request, and a 20 MB body must not be parsed just to
            // be thrown away by the cap afterwards
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
        // before input() parses anything: this runs in the traced application's own
        // request, and a 20 MB json body must not be decoded just to be dropped by
        // the cap afterwards. A multipart body is left to the check below - its
        // length is mostly file bytes, and of a file only the name and the size is
        // recorded
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

        // Laravel parses form and JSON bodies into input(); an XML one it leaves
        // alone, so without this a SOAP or XML-API request is traced with no body at
        // all. Not conditional on input() being empty: input() merges the query bag,
        // so a single `?wsdl` - the standard shape of these calls - used to suppress
        // the body entirely
        $body = $this->readXmlRequestBody($request);

        $parameters = $body ? [...$parameters, ...$body] : $parameters;

        // Content-Length is absent on a chunked request and misleading on a multipart
        // one, so what was actually collected is measured too. Above this the masker
        // refuses to read a value, and what it will not read must not be recorded
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
     * Whether the collected parameters weigh more than the cap.
     *
     * Counted by walking the values rather than by encoding them: encoding an
     * oversized payload just to measure it is the cost the cap exists to avoid. The
     * walk stops at the first byte over the limit, so an 8 MB body costs no more to
     * reject than a 1 MB one.
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

        // the cheapest check first, then the size, and only then anything that reads
        // or parses the body - all three before the parse, because this is the traced
        // application's own request path
        if (!BodyDecoder::isXmlContentType($contentType)) {
            return [];
        }

        $length = $request->headers->get('Content-Length');

        if (is_numeric($length) && (int) $length > $this->maxResponseBytes) {
            return [
                '__skipped' => 'request_too_large',
            ];
        }

        $content = $request->getContent();

        if (strlen($content) > $this->maxResponseBytes) {
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
