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
 */
class RequestWatcher implements WatcherInterface
{
    /**
     * @var array<array{trace_id: string, boot_time: float, started_at: Carbon, logged_at: Carbon}>
     */
    protected array $requests = [];
    /**
     * @var string[]
     */
    protected array $exceptedPaths = [];

    protected RequestDataFormatters $formatters;

    protected int $maxResponseBytes = 1048576;

    public function __construct(
        protected readonly Application $app,
        protected readonly Processor $processor,
    ) {
        $this->formatters = new RequestDataFormatters();
    }

    public function register(?array $config): void
    {
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

        $startedAt = $startedAt?->clone()->setTimezone('UTC') ?? Carbon::now('UTC');

        $loggedAt = Carbon::now('UTC');

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

        $this->requests[] = [
            'trace_id'   => $traceId,
            'boot_time'  => $bootTime,
            'started_at' => $startedAt,
            'logged_at'  => $loggedAt,
        ];
    }

    public function handleRequestHandled(RequestHandled $event): void
    {
        if ($this->isRequestByPatterns($event->request, $this->exceptedPaths)) {
            return;
        }

        $requestData = array_pop($this->requests);

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
        $url = str_replace($request->root(), '', $request->fullUrl());

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

        return [
            'ip_address'  => $request->ip(),
            'uri'         => $this->prepareUrl($url),
            'method'      => $request->method(),
            'action'      => $action,
            'middlewares' => $middlewares,
        ];
    }

    /**
     * @return string[]
     */
    protected function getPreTags(Request $request): array
    {
        $url = str_replace($request->root(), '', $request->fullUrl());

        return [
            $this->prepareUrl($url),
        ];
    }

    /**
     * @return string[]
     */
    protected function getPostTags(Request $request, Response $response): array
    {
        /**
         * for support for Laravel 10, 12
         *
         * @var Route|object|string|null $route
         */
        $route = $request->route();

        if (!$route) {
            return [];
        }

        if (is_string($route)) {
            return [
                $route,
            ];
        }

        if (!$route instanceof Route) {
            return [];
        }

        return [
            $this->prepareUrl($route->uri()),
            ...array_values($route->originalParameters()),
        ];
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
     * @return array<string, mixed>
     */
    protected function prepareRequestHeaders(Request $request): array
    {
        $uri = $this->getRequestPath($request);

        $headers = $request->headers->all();

        foreach ($this->formatters->getItems() as $formatter) {
            $headers = $formatter->prepareRequestHeaders($uri, $headers);
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareRequestParameters(Request $request): array
    {
        $uri = $this->getRequestPath($request);

        $parameters = $this->getRequestParameters($request);

        foreach ($this->formatters->getItems() as $formatter) {
            $parameters = $formatter->prepareRequestParameters($uri, $parameters);
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareResponseHeaders(Request $request, Response $response): array
    {
        $uri = $this->getRequestPath($request);

        $headers = $response->headers->all();

        foreach ($this->formatters->getItems() as $formatter) {
            $headers = $formatter->prepareResponseHeaders($uri, $headers);
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareResponseData(Request $request, Response $response): array
    {
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

        if ($request->acceptsJson()) {
            $url = $this->getRequestPath($request);

            $content = $response->getContent();

            if ($content === false) {
                return [];
            }

            if (strlen($content) > $this->maxResponseBytes) {
                return [
                    '__skipped' => 'response_too_large',
                ];
            }

            $dataResolver = new DataResolver(
                fn() => json_decode($content, true) ?: []
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
     * @return array<string, mixed>
     */
    protected function getRequestParameters(Request $request): array
    {
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

        return array_replace_recursive($request->input(), $files);
    }

    /**
     * @param array<string, mixed>|null $config
     */
    protected function parseConfig(?array $config): void
    {
        if ($config === null) {
            return;
        }

        $this->exceptedPaths = $config['excepted_paths'] ?? [];

        /** @var array<string, RequestDataFormatter> $formatterMap */
        $formatterMap = [];

        $inputFullHiding = $config['input']['full_hiding'] ?? [];

        foreach ($inputFullHiding as $urlPattern) {
            $formatterMap[$urlPattern] ??= new RequestDataFormatter([$urlPattern]);
            $formatterMap[$urlPattern]->setHideAllRequestParameters(true);
        }

        $inputMaskHeadersMasking = $config['input']['headers_masking'] ?? [];

        foreach ($inputMaskHeadersMasking as $urlPattern => $headers) {
            $formatterMap[$urlPattern] ??= new RequestDataFormatter([$urlPattern]);
            $formatterMap[$urlPattern]->addRequestHeaders($headers);
        }

        $inputParametersMasking = $config['input']['parameters_masking'] ?? [];

        foreach ($inputParametersMasking as $urlPattern => $parameters) {
            $formatterMap[$urlPattern] ??= new RequestDataFormatter([$urlPattern]);
            $formatterMap[$urlPattern]->addRequestParameters($parameters);
        }

        $outputFullHiding = $config['output']['full_hiding'] ?? [];

        foreach ($outputFullHiding as $urlPattern) {
            $formatterMap[$urlPattern] ??= new RequestDataFormatter([$urlPattern]);
            $formatterMap[$urlPattern]->setHideAllResponseData(true);
        }

        $outputHeadersMasking = $config['output']['headers_masking'] ?? [];

        foreach ($outputHeadersMasking as $urlPattern => $headers) {
            $formatterMap[$urlPattern] ??= new RequestDataFormatter([$urlPattern]);
            $formatterMap[$urlPattern]->addResponseHeaders($headers);
        }

        $outputFieldsMasking = $config['output']['fields_masking'] ?? [];

        foreach ($outputFieldsMasking as $urlPattern => $fields) {
            $formatterMap[$urlPattern] ??= new RequestDataFormatter([$urlPattern]);
            $formatterMap[$urlPattern]->addResponseFields($fields);
        }

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
