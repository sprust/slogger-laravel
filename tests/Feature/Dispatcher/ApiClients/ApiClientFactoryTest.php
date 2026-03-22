<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\ApiClients;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use RuntimeException;
use SLoggerLaravel\Dispatcher\ApiClients\ApiClientFactory;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\SocketClient;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class ApiClientFactoryTest extends BaseTestCase
{
    /**
     * @throws BindingResolutionException
     */
    public function testCreateHttpAndSocketClients(): void
    {
        config()->set('slogger.token', 'token-123');
        config()->set('slogger.dispatchers.queue.api_clients.socket.url', 'tcp://127.0.0.1:1234');

        Log::shouldReceive('channel')
            ->andReturn(new NullLogger());

        $factory = $this->getApp()->make(ApiClientFactory::class);

        self::assertInstanceOf(SocketClient::class, $factory->create('socket'));
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCreateThrowsOnUnknownClient(): void
    {
        $factory = $this->getApp()->make(ApiClientFactory::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown api client [unknown]');

        $factory->create('unknown');
    }
}
