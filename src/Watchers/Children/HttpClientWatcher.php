<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\DataResolver;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Guzzle\GuzzleHandlerFactory;
use SLoggerLaravel\Helpers\BodyDecoder;
use SLoggerLaravel\Helpers\DataFormatter;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Processor;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Watchers\WatcherInterface;
use Throwable;

class HttpClientWatcher implements WatcherInterface
{
    /**
     * Bodies at or above this are described rather than recorded, in either
     * direction: telemetry must not double the memory a request needs.
     */
    protected const MAX_BODY_BYTES = 1000000;
    protected string $headerTraceIdKey;
    protected ?string $headerParentTraceIdKey;

    /**
     * @var array<string, array{trace_id: string, started_at: Carbon}>
     */
    protected array $requests = [];

    public function __construct(
        protected Processor $processor,
        protected TraceIdContainer $traceIdContainer,
        WatchersConfig $watchersConfig
    ) {
        $this->headerTraceIdKey       = Str::random(20);
        $this->headerParentTraceIdKey = $watchersConfig->requestsHeaderParentTraceIdKey();
    }

    public function register(?array $config): void
    {
        /** @see GuzzleHandlerFactory */

        // a request whose promise never settles (an abandoned pool, a job killed by
        // the timeout signal) is closed by the processor's sweep, and neither
        // response hook ever runs to clear its entry
        $this->processor->onTraceInterrupted(
            function (string $traceId): void {
                unset($this->requests[$traceId]);
            }
        );
    }

    final public function handleRequest(RequestInterface $request): RequestInterface
    {
        $requestResult = $this->processor->handleWatcher(
            function () use ($request) {
                return $this->onHandleRequest($request);
            }
        );

        return $requestResult ?: $request;
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
        $this->processor->handleWatcher(
            function () use ($request, $options, $response, $formatters) {
                $this->onHandleResponse(
                    request: $request,
                    options: $options,
                    response: $response,
                    formatters: $formatters
                );
            }
        );
    }

    final public function handleInvalidResponse(
        RequestInterface $request,
        Throwable $exception,
        RequestDataFormatters $formatters
    ): void {
        $this->processor->handleWatcher(
            function () use ($request, $exception, $formatters) {
                $this->onHandleInvalidResponse(
                    request: $request,
                    exception: $exception,
                    formatters: $formatters
                );
            }
        );
    }

    protected function onHandleRequest(RequestInterface $request): RequestInterface
    {
        if (!$this->isSubscribeRequest($request)) {
            return $request;
        }

        $loggedAt = Carbon::now();

        // detached: `Http::pool()` keeps several requests in flight at once, so an
        // outbound request neither nests into another one nor finishes in the order
        // it started
        $traceId = $this->processor->startAndGetDetachedTraceId(
            type: 'http-client',
            tags: [],
            data: $this->getCommonRequestData($request),
            loggedAt: $loggedAt,
        );

        $this->requests[$traceId] = [
            'trace_id'   => $traceId,
            'started_at' => $loggedAt,
        ];

        $request = $request->withHeader($this->headerTraceIdKey, $traceId);

        if ($this->headerParentTraceIdKey) {
            // the called service hangs its trace under *this call*, not under whatever
            // encloses it here: the outbound trace always exists, an enclosing one may
            // not, and a call traced on both sides should join up either way
            $request = $request->withHeader(
                $this->headerParentTraceIdKey,
                $traceId
            );
        }

        return $request;
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

        $traceId = $request->getHeader($this->headerTraceIdKey)[0] ?? null;

        if ($traceId === null) {
            return;
        }

        $requestData = $this->requests[$traceId] ?? null;

        if (!$requestData) {
            return;
        }

        // drop the tracked request before stopping the trace so the entry is
        // always cleared, even if stop() throws: otherwise long-running processes
        // (queue workers, Octane) leak one entry per outbound request.
        unset($this->requests[$traceId]);

        /** @var Carbon $startedAt */
        $startedAt = $requestData['started_at'];

        $uri = $this->getRequestUrl($request);

        $statusCode = $response->getStatusCode();

        $this->processor->stopDetached(
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

    protected function onHandleInvalidResponse(
        RequestInterface $request,
        Throwable $exception,
        RequestDataFormatters $formatters
    ): void {
        if (!$this->isSubscribeRequest($request)) {
            return;
        }

        $traceId = $request->getHeader($this->headerTraceIdKey)[0] ?? null;

        if ($traceId === null) {
            return;
        }

        $requestData = $this->requests[$traceId] ?? null;

        if (!$requestData) {
            return;
        }

        // drop the tracked request before stopping the trace so the entry is
        // always cleared, even if stop() throws: otherwise long-running processes
        // (queue workers, Octane) leak one entry per outbound request.
        unset($this->requests[$traceId]);

        /** @var Carbon $startedAt */
        $startedAt = $requestData['started_at'];

        $uri = $this->getRequestUrl($request);

        $this->processor->stopDetached(
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
     * @return array<int|string, mixed>
     */
    protected function prepareRequestParameters(
        RequestInterface $request,
        RequestDataFormatters $formatters
    ): array {
        $parameters = $this->readRequestBody($request);

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
     * Reading an outbound body is the one thing tracing does that can change what the
     * application sends, or how much memory it needs to send it. Both guards below
     * exist for that reason, and both mirror what the response path already does.
     *
     * @return array<int|string, mixed>
     */
    protected function readRequestBody(RequestInterface $request): array
    {
        $body = $request->getBody();

        if (!$body->isSeekable()) {
            // reading it would consume the body the client is about to send
            return [
                '__skipped' => 'non_seekable_body',
            ];
        }

        $size = $body->getSize();

        if (!is_null($size) && $size >= self::MAX_BODY_BYTES) {
            // an upload must not be copied into memory a second time just to trace it
            return [
                '__cleaned' => "--cleaned:big-size-$size--",
            ];
        }

        $body->rewind();

        $contents = $body->getContents();

        $body->rewind();

        if (strlen($contents) >= self::MAX_BODY_BYTES) {
            // getSize() is null for a chunked or generated body, so the cap has to be
            // re-checked against what was actually read
            return [
                '__cleaned' => '--cleaned:big-size--',
            ];
        }

        return BodyDecoder::decode($contents, $request->getHeaderLine('Content-Type'));
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
     * @return array<int|string, mixed>
     */
    protected function prepareResponseBody(
        RequestInterface $request,
        ResponseInterface $response,
        RequestDataFormatters $formatters
    ): array {
        $body = $response->getBody();

        $size = $body->getSize();

        if (!is_null($size) && $size >= self::MAX_BODY_BYTES) {
            return [
                '__cleaned' => "--cleaned:big-size-$size--",
            ];
        }

        if (!$body->isSeekable()) {
            // reading it would consume the body the application is about to read
            return [
                '__skipped' => 'non_seekable_body',
            ];
        }

        $body->rewind();

        $url = $this->getRequestPath($request);

        $contentType = $response->getHeaderLine('Content-Type');

        $dataResolver = new DataResolver(
            function () use ($body, $contentType): array {
                $contents = $body->getContents();

                if (strlen($contents) >= self::MAX_BODY_BYTES) {
                    // getSize() is null for a chunked or generated body, so the cap
                    // has to be re-checked against what was actually read
                    return [
                        '__cleaned' => '--cleaned:big-size--',
                    ];
                }

                return BodyDecoder::decode($contents, $contentType);
            }
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
     * The query string is split out of the url and carried as data: a url is a tag
     * and a title, and nothing masks those, while `query` is matched key by key and
     * `query_string` parameter by parameter by the dispatcher job.
     *
     * @return array<string, mixed>
     */
    protected function getCommonRequestData(RequestInterface $request): array
    {
        $queryString = $request->getUri()->getQuery();

        $query = [];

        parse_str($queryString, $query);

        return [
            'uri'          => $this->getRequestUrl($request),
            'method'       => $request->getMethod(),
            'query'        => $query,
            'query_string' => $queryString === '' ? null : $queryString,
        ];
    }

    /**
     * The request url with everything secret-shaped stripped: the query string,
     * which travels as data instead, and the userinfo, which is a password sitting
     * in a url. Both would otherwise end up in a tag, and nothing masks a tag.
     *
     * What is left is the path, and a secret bound into a path - `/keys/sk-live-x/
     * rotate` - still gets through. Nothing here can tell which path segment is a
     * secret; a value pattern can, if it has a shape worth matching.
     */
    protected function getRequestUrl(RequestInterface $request): string
    {
        return (string) $request->getUri()
            ->withQuery('')
            ->withUserInfo('');
    }

    protected function getRequestPath(RequestInterface $request): string
    {
        return (string) $request->getUri();
    }
}
