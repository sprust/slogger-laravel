<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SLoggerLaravel\DataResolver;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Guzzle\GuzzleHandlerFactory;
use SLoggerLaravel\Helpers\DataFormatter;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\Watchers\AbstractWatcher;
use Throwable;

class HttpClientWatcher extends AbstractWatcher
{
    protected ?string $headerTraceIdKey;
    protected ?string $headerParentTraceIdKey;

    /**
     * @var array<string, array{trace_id: string, started_at: Carbon}>
     */
    protected array $requests = [];

    protected function init(): void
    {
        $this->headerTraceIdKey       = Str::random(20);
        $this->headerParentTraceIdKey = $this->loggerConfig->requestsHeaderParentTraceIdKey();
    }

    public function register(): void
    {
        /** @see GuzzleHandlerFactory */
    }

    final public function handleRequest(RequestInterface $request): RequestInterface
    {
        $requestResult = $this->safeHandleWatching(
            function () use ($request) {
                return $this->onHandleRequest($request);
            }
        );

        return $requestResult ?: $request;
    }

    protected function onHandleRequest(RequestInterface $request): RequestInterface
    {
        if (!$this->isSubscribeRequest($request)) {
            return $request;
        }

        $loggedAt = now();

        $traceId = $this->processor->startAndGetTraceId(
            type: 'http-client',
            tags: [],
            data: $this->getCommonRequestData($request),
            loggedAt: $loggedAt,
            customParentTraceId: null,
        );

        $this->requests[$traceId] = [
            'trace_id'   => $traceId,
            'started_at' => $loggedAt,
        ];

        $request = $request->withHeader($this->headerTraceIdKey, $traceId);

        if ($this->headerParentTraceIdKey) {
            $request = $request->withHeader(
                $this->headerParentTraceIdKey,
                $this->traceIdContainer->getParentTraceId()
            );
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $options
     */
    final public function handleResponse(
        RequestInterface $request,
        array $options,
        ResponseInterface $response,
        RequestDataFormatters $formatters
    ): void {
        $this->safeHandleWatching(
            function () use ($request, $options, $response, $formatters) {
                $this->onHandleResponse($request, $options, $response, $formatters);
            }
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function onHandleResponse(
        RequestInterface $request,
        array $options,
        ResponseInterface $response,
        RequestDataFormatters $formatters
    ): void {
        if (!$this->isSubscribeRequest($request)) {
            return;
        }

        $traceId = $request->getHeader($this->headerTraceIdKey)[0];

        $requestData = $this->requests[$traceId] ?? null;

        if (!$requestData) {
            return;
        }

        /** @var Carbon $startedAt */
        $startedAt = $requestData['started_at'];

        $uri = (string) $request->getUri();

        $statusCode = $response->getStatusCode();

        $this->processor->stop(
            traceId: $traceId,
            status: ($statusCode >= 200 && $statusCode < 300)
                ? TraceStatusEnum::Success->value
                : TraceStatusEnum::Failed->value,
            tags: $uri ? [$uri] : [],
            data: [
                ...$this->getCommonRequestData($request),
                'request'  => [
                    'headers' => $this->prepareRequestHeaders($request, $formatters),
                    'payload' => $this->prepareRequestParameters($request, $formatters),
                ],
                'response' => [
                    'status_code' => $statusCode,
                    'headers'     => $this->prepareResponseHeaders($request, $response, $formatters),
                    'body'        => $this->prepareResponseBody($request, $response, $formatters),
                ],
            ],
            duration: TraceHelper::calcDuration($startedAt),
            parentLoggedAt: $startedAt,
        );
    }

    final public function handleInvalidResponse(
        RequestInterface $request,
        Throwable $exception,
        RequestDataFormatters $formatters
    ): void {
        $this->safeHandleWatching(
            function () use ($request, $exception, $formatters) {
                $this->onHandleInvalidResponse($request, $exception, $formatters);
            }
        );
    }

    protected function onHandleInvalidResponse(
        RequestInterface $request,
        Throwable $exception,
        RequestDataFormatters $formatters
    ): void {
        if (!$this->isSubscribeRequest($request)) {
            return;
        }

        $traceId = $request->getHeader($this->headerTraceIdKey)[0];

        $requestData = $this->requests[$traceId] ?? null;

        if (!$requestData) {
            return;
        }

        /** @var Carbon $startedAt */
        $startedAt = $requestData['started_at'];

        $uri = (string) $request->getUri();

        $this->processor->stop(
            traceId: $traceId,
            status: TraceStatusEnum::Failed->value,
            tags: $uri ? [$uri] : [],
            data: [
                ...$this->getCommonRequestData($request),
                'request'   => [
                    'headers' => $this->prepareRequestHeaders($request, $formatters),
                    'payload' => $this->prepareRequestParameters($request, $formatters),
                ],
                'exception' => DataFormatter::exception($exception),
            ],
            duration: TraceHelper::calcDuration($startedAt),
            parentLoggedAt: $startedAt,
        );
    }

    protected function isSubscribeRequest(RequestInterface $request): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareRequestHeaders(
        RequestInterface $request,
        RequestDataFormatters $formatters
    ): array {
        $headers = $request->getHeaders();

        foreach ($formatters->getItems() as $formatter) {
            $headers = $formatter->prepareRequestHeaders(
                url: $this->getRequestPath($request),
                headers: $headers
            );
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareRequestParameters(
        RequestInterface $request,
        RequestDataFormatters $formatters
    ): array {
        $body = $request->getBody();

        $body->rewind();

        $parameters = json_decode($body->getContents(), true) ?: [];

        $body->rewind();

        $url = $this->getRequestPath($request);

        foreach ($formatters->getItems() as $formatter) {
            $parameters = $formatter->prepareRequestParameters(
                url: $url,
                parameters: $parameters
            );
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareResponseHeaders(
        RequestInterface $request,
        ResponseInterface $response,
        RequestDataFormatters $formatters
    ): array {
        $url = $this->getRequestPath($request);

        $headers = $response->getHeaders();

        foreach ($formatters->getItems() as $formatter) {
            $headers = $formatter->prepareResponseHeaders(
                url: $url,
                headers: $headers
            );
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareResponseBody(
        RequestInterface $request,
        ResponseInterface $response,
        RequestDataFormatters $formatters
    ): array {
        $body = $response->getBody();

        $size = $body->getSize();

        if ($size >= 1000000) { // 1mb
            return [
                '__cleaned' => "--cleaned:big-size-$size--",
            ];
        }

        $body->rewind();

        $url = $this->getRequestPath($request);

        $dataResolver = new DataResolver(
            fn() => json_decode($body->getContents(), true) ?: []
        );

        foreach ($formatters->getItems() as $formatter) {
            $continue = $formatter->prepareResponseData(
                url: $url,
                dataResolver: $dataResolver
            );

            if (!$continue) {
                break;
            }
        }

        $data = $dataResolver->getData();

        $body->rewind();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCommonRequestData(RequestInterface $request): array
    {
        return [
            'uri'    => $request->getUri(),
            'method' => $request->getMethod(),
        ];
    }

    protected function getRequestPath(RequestInterface $request): string
    {
        return $request->getUri();
    }
}
