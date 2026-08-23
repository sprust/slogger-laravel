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
use SLoggerLaravel\Watchers\OpenTraces;
use SLoggerLaravel\Watchers\WatcherInterface;
use Throwable;

class HttpClientWatcher implements WatcherInterface
{
    /**
     * Bodies at or above this are described rather than recorded, in either
     * direction: telemetry must not double the memory a request needs. The masker's
     * own limit, so a body that is recorded is a body that can be read.
     */
    protected const MAX_BODY_BYTES = MaskHelper::MAX_READABLE_BYTES;
    protected string $headerTraceIdKey;
    protected ?string $headerParentTraceIdKey;

    protected OpenTraces $openRequests;

    public function __construct(
        protected Processor $processor,
        WatchersConfig $watchersConfig
    ) {
        $this->headerTraceIdKey       = Str::random(20);
        $this->headerParentTraceIdKey = $watchersConfig->requestsHeaderParentTraceIdKey();
        $this->openRequests           = new OpenTraces();
    }

    public function register(?array $config): void
    {
        /** @see GuzzleHandlerFactory */

        // a request whose promise never settles (an abandoned pool, a job killed by
        // the timeout signal) is closed by the processor's sweep, and neither
        // response hook ever runs to clear its entry
        $this->processor->onTraceInterrupted(
            function (string $traceId): void {
                $this->openRequests->forget($traceId);
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

        // detached: `Http::pool()` keeps several requests in flight at once, so an
        // outbound request neither nests into another one nor finishes in the order
        // it started
        $traceId = $this->processor->startAndGetDetachedTraceId(
            type: 'http-client',
            tags: [],
            data: $this->getCommonRequestData($request),
            loggedAt: $loggedAt,
        );

        $this->openRequests->open($traceId, ['started_at' => $loggedAt]);

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
     * The trace this response belongs to, taken out of the open ones - by the id the
     * outbound request carries, since responses arrive in no particular order.
     *
     * Taken before the trace is stopped, so a stop() that throws still leaves nothing
     * behind: a long-lived worker otherwise leaks one entry per outbound request.
     *
     * @return array{trace_id: string, started_at: Carbon}|null
     */
    protected function takeOpenRequest(RequestInterface $request): ?array
    {
        $traceId = $request->getHeader($this->headerTraceIdKey)[0] ?? null;

        if (!is_string($traceId)) {
            return null;
        }

        /** @var array{trace_id: string, started_at: Carbon}|null $requestData */
        $requestData = $this->openRequests->take($traceId);

        return $requestData;
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
     * Reads a message body without changing what anyone else will do with it.
     *
     * Reading an outbound body is the one thing tracing does that can change what the
     * application sends, or how much memory it needs to send it - and the inbound one
     * has the same problem with what the application is about to read. One copy for
     * both: the two used to check the same three things in a different order, so the
     * same stream was described one way going out and another coming back.
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
            // getSize() is null for a chunked or generated body, so the cap has to be
            // re-checked against what was actually read
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

        // lazily: a formatter is allowed to say the body is not worth recording, and
        // then nothing here touches the stream at all
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

    /**
     * The url a formatter matches its patterns against - the whole of it, query and
     * all. It is never recorded: what a trace carries is getRequestUrl().
     */
    protected function getRequestUri(RequestInterface $request): string
    {
        return (string) $request->getUri();
    }
}
