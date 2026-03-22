<?php

namespace SLoggerLaravel\Dispatcher\ApiClients;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\Connection;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\SocketClient;

readonly class ApiClientFactory
{
    protected string $apiToken;

    public function __construct(
        protected GeneralConfig $config,
        protected DispatcherQueueConfig $queueConfig,
    ) {
        $this->apiToken = $this->config->getToken();
    }

    public function create(string $apiClientName): ApiClientInterface
    {
        return match ($apiClientName) {
            'socket' => $this->createSocket(),
            default  => throw new RuntimeException("Unknown api client [$apiClientName]"),
        };
    }

    private function createSocket(): SocketClient
    {
        return new SocketClient(
            apiToken: $this->apiToken,
            connection: new Connection(
                socketAddress: $this->queueConfig->getSocketClientUrl(),
                logger: Log::channel(
                    $this->config->getLogChannel()
                )
            ),
        );
    }
}
