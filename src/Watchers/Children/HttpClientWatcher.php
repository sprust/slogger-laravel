<?php

namespace SLoggerLaravel\Watchers\Children;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\DataResolver;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Guzzle\GuzzleHandlerFactory;
use SLoggerLaravel\Helpers\BodyDecoder;
use SLoggerLaravel\Helpers\DataFormatter;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Processor;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\Watchers\WatcherInterface;
use Throwable;

class HttpClientWatcher implements WatcherInterface
{
    /**
     * Bodies at or above this are described rather than recorded: telemetry must not
     * double the memory a request needs. The masker's own limit.
     */
    protected const MAX_BODY_BYTES = MaskHelper::MAX_READABLE_BYTES;
    protected string $headerTraceIdKey;
    protected ?string $headerParentTraceIdKey;

    /**
     * Outbound requests in flight, by the trace id their header carries.
     *
     * @var array<string, array{started_at: Carbon}>
     */
    protected array $requests = [];

    public function __construct(
        protected Processor $processor,
        WatchersConfig $watchersConfig
    ) {
        $this->headerTraceIdKey       = Str::random(20);
        $this->headerParentTraceIdKey = $watchersConfig->requestsHeaderParentTraceIdKey();
    }

    public function register(?array $config): void
    {
        /** @see GuzzleHandlerFactory */

        // a promise that never settles is closed by the processor's sweep, and no
        // response hook runs to clear its entry
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
        $loggedAt = Carbon::now();

        // detached: `Http::pool()` keeps several in flight, so they neither nest nor
        // finish in order
        $traceId = $this->processor->startAndGetDetachedTraceId(
            type: 'http-client',
            tags: [],
            data: $this->getCommonRequestData($request),
            loggedAt: $loggedAt,
        );

        $this->requests[$traceId] = ['started_at' => $loggedAt];

        $request = $request->withHeader($this->headerTraceIdKey, $traceId);

        if ($this->headerParentTraceIdKey) {
            // the called service hangs under *this call*: the outbound trace always
            // exists, an enclosing one may not
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
        $requestData = $this->takeOpenRequest($request);

        if (!$requestData) {
            return;
        }

        $traceId = $requestData['trace_id'];

        /** @var Carbon $startedAt */
        $startedAt = $requestData['started_at'];

        $uri = $this->getRequestUrl($request);

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

    protected function onHandleInvalidResponse(
        RequestInterface $request,
        Throwable $exception,
        RequestDataFormatters $formatters
    ): void {
        $requestData = $this->takeOpenRequest($request);

        if (!$requestData) {
            return;
        }

        $traceId = $requestData['trace_id'];

        /** @var Carbon $startedAt */
        $startedAt = $requestData['started_at'];

        $uri = $this->getRequestUrl($request);

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

    /**
     * By the id the outbound request carries, since responses arrive in no particular
     * order. Taken before the trace is stopped, so a stop() that throws leaks nothing.
     *
     * @return array{trace_id: string, started_at: Carbon}|null
     */
    protected function takeOpenRequest(RequestInterface $request): ?array
    {
        $traceId = $request->getHeader($this->headerTraceIdKey)[0] ?? null;

        if (!is_string($traceId)) {
            return null;
        }

        $requestData = $this->requests[$traceId] ?? null;

        if (is_null($requestData)) {
            return null;
        }

        unset($this->requests[$traceId]);

        return ['trace_id' => $traceId, ...$requestData];
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
                url: $this->getRequestUri($request),
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
        $parameters = $this->readBody($request->getBody(), $request->getHeaderLine('Content-Type'));

        $url = $this->getRequestUri($request);

        foreach ($formatters->getItems() as $formatter) {
            $parameters = $formatter->prepareRequestParameters(
                url: $url,
                parameters: $parameters
            );
        }

        return $parameters;
    }

    /**
     * Reading a body is the one thing tracing does that can change what the
     * application sends, or how much memory it needs. One copy for both directions.
     *
     * @return array<int|string, mixed>
     */
    protected function readBody(StreamInterface $body, string $contentType): array
    {
        $size = $body->getSize();

        if (!is_null($size) && $size >= self::MAX_BODY_BYTES) {
            // an upload must not be copied into memory a second time just to trace it
            return [
                '__cleaned' => "--cleaned:big-size-$size--",
            ];
        }

        if (!$body->isSeekable()) {
            // reading it would consume the body someone else is about to
            return [
                '__skipped' => 'non_seekable_body',
            ];
        }

        $body->rewind();

        $contents = $body->getContents();

        $body->rewind();

        if (strlen($contents) >= self::MAX_BODY_BYTES) {
            // getSize() is null for a chunked body, so the cap is re-checked
            return [
                '__cleaned' => '--cleaned:big-size--',
            ];
        }

        return BodyDecoder::decode($contents, $contentType);
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareResponseHeaders(
        RequestInterface $request,
        ResponseInterface $response,
        RequestDataFormatters $formatters
    ): array {
        $url = $this->getRequestUri($request);

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

        $url = $this->getRequestUri($request);

        $contentType = $response->getHeaderLine('Content-Type');

        // lazily: a formatter may say the body is not worth recording
        $dataResolver = new DataResolver(
            fn(): array => $this->readBody($body, $contentType)
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

        return $dataResolver->getData();
    }

    /**
     * The query string is carried as data, not in the url: nothing masks a tag, while
     * `query` and `query_string` are matched by the dispatcher job.
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
     * Query string and userinfo stripped: both would end up in a tag, and nothing
     * masks a tag. A secret bound into the path is left to the value patterns.
     */
    protected function getRequestUrl(RequestInterface $request): string
    {
        return (string) $request->getUri()
            ->withQuery('')
            ->withUserInfo('');
    }

    /**
     * What a formatter matches its patterns against - never recorded; a trace carries
     * getRequestUrl().
     */
    protected function getRequestUri(RequestInterface $request): string
    {
        return (string) $request->getUri();
    }
}
