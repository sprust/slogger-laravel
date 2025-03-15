<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue\ApiClients;

use Exception;
use Grpc\ChannelCredentials;
use GuzzleHttp\Client;
use RuntimeException;
use SLoggerGrpc\Services\TraceCollectorGrpcService;
use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Configs\DispatcherTransporterConfig;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\Items\Queue\ApiClients\Grpc\GrpcClient;
use SLoggerLaravel\Dispatcher\Items\Queue\ApiClients\Http\HttpClient;

readonly class ApiClientFactory
{
    private string $apiToken;

    public function __construct(
        GeneralConfig $config,
        private DispatcherQueueConfig $queueConfig,
    ) {
        $this->apiToken = $config->getToken();
    }

    public function create(string $apiClientName): ApiClientInterface
    {
        return match ($apiClientName) {
            'http' => $this->createHttp(),
            'grpc' => $this->createGrpc(),
            default => throw new RuntimeException("Unknown api client [$apiClientName]"),
        };
    }

    private function createHttp(): HttpClient
    {
        $url = $this->queueConfig->getHttpClientUrl();

        return new HttpClient(
            new Client([
                'headers'  => [
                    'Authorization'    => "Bearer $this->apiToken",
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Content-Type'     => 'application/json',
                    'Accept'           => 'application/json',
                ],
                'base_uri' => $url,
            ])
        );
    }

    private function createGrpc(): GrpcClient
    {
        if (!class_exists(TraceCollectorGrpcService::class)) {
            throw new RuntimeException(
                'The package slogger/grpc is not installed'
            );
        }

        $url = $this->queueConfig->getGrpcClientUrl();

        try {
            return new GrpcClient(
                apiToken: $this->apiToken,
                grpcService: new TraceCollectorGrpcService(
                    hostname: $url,
                    opts: [
                        'credentials' => ChannelCredentials::createInsecure(),
                    ]
                )
            );
        } catch (Exception $exception) {
            throw new RuntimeException($exception->getMessage());
        }
    }
}
