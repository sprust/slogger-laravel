<?php

namespace SLoggerLaravel\Guzzle;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
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
        return Middleware::tap(
            after: function (
                RequestInterface $request,
                array $options,
                PromiseInterface $response
            ) use ($formatters): void {
                // never wait() here: tap's `after` runs synchronously right after the
                // handler, so waiting would finish every request before the next one is
                // even started and quietly turn Http::pool() into a serial loop
                $response->then(
                    function (ResponseInterface $responseResolved) use ($request, $options, $formatters) {
                        $this->httpClientWatcher->handleResponse(
                            request: $request,
                            options: $options,
                            response: $responseResolved,
                            formatters: $formatters
                        );

                        return $responseResolved;
                    },
                    function (mixed $reason) use ($request, $formatters): void {
                        $this->httpClientWatcher->handleInvalidResponse(
                            request: $request,
                            exception: $reason instanceof Throwable
                                ? $reason
                                : new RuntimeException((string) (is_scalar($reason) ? $reason : 'unknown error')),
                            formatters: $formatters
                        );
                    }
                );
            }
        );
    }
}
