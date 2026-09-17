<?php

namespace SLoggerLaravel\Guzzle;

use Closure;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\State;
use SLoggerLaravel\Watchers\Children\HttpClientWatcher;
use Throwable;

readonly class GuzzleHandlerFactory
{
    public function __construct(
        private State $loggerState,
        private HttpClientWatcher $httpClientWatcher
    ) {
    }

    public function prepareHandler(
        RequestDataFormatters $formatters,
        ?HandlerStack $handlerStack = null
    ): HandlerStack {
        $handlersStack = $handlerStack ?: HandlerStack::create();

        if ($this->loggerState->isWatcherEnabled(HttpClientWatcher::class)) {
            $handlersStack->push($this->request());
            $handlersStack->push($this->response($formatters));
        }

        return $handlersStack;
    }

    private function request(): callable
    {
        return Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            return $this->httpClientWatcher->handleRequest($request);
        });
    }

    private function response(RequestDataFormatters $formatters): callable
    {
        return function (callable $handler) use ($formatters): callable {
            return function (RequestInterface $request, array $options) use ($handler, $formatters): PromiseInterface {
                // per call, not in the store: the handler reports the stats before the
                // promise settles, and Http::pool() keeps several calls in flight
                $transferStats = null;

                $handlerOptions = [
                    ...$options,
                    'on_stats' => $this->collectStats($options['on_stats'] ?? null, $transferStats),
                ];

                /** @var PromiseInterface $response */
                $response = $handler($request, $handlerOptions);

                // never wait() here: waiting turns Http::pool() into a serial loop
                $response->then(
                    function (ResponseInterface $responseResolved) use (
                        $request,
                        $options,
                        $formatters,
                        &$transferStats
                    ) {
                        $this->httpClientWatcher->handleResponse(
                            request: $request,
                            options: $options,
                            response: $responseResolved,
                            formatters: $formatters,
                            transferStats: $transferStats
                        );

                        return $responseResolved;
                    },
                    function (mixed $reason) use ($request, $formatters, &$transferStats): void {
                        $this->httpClientWatcher->handleInvalidResponse(
                            request: $request,
                            exception: $reason instanceof Throwable
                                ? $reason
                                : new RuntimeException((string) (is_scalar($reason) ? $reason : 'unknown error')),
                            formatters: $formatters,
                            transferStats: $transferStats
                        );
                    }
                );

                return $response;
            };
        };
    }

    /**
     * The caller's own `on_stats` is called on: Laravel's Http client sets one and fills
     * `$response->transferStats` from it.
     */
    private function collectStats(mixed $onStats, ?TransferStats &$transferStats): Closure
    {
        return static function (TransferStats $stats) use ($onStats, &$transferStats): void {
            $transferStats = $stats;

            if (is_callable($onStats)) {
                $onStats($stats);
            }
        };
    }
}
