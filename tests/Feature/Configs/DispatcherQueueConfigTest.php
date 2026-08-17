<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Configs;

use RuntimeException;
use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class DispatcherQueueConfigTest extends BaseTestCase
{
    private DispatcherQueueConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new DispatcherQueueConfig();
    }

    public function testGetConnectionReturnsConfiguredValue(): void
    {
        config()->set('slogger.dispatchers.queue.connection', 'rabbitmq');

        self::assertSame('rabbitmq', $this->config->getConnection());
    }

    public function testGetConnectionFailsFastWhenNotSet(): void
    {
        config()->set('slogger.dispatchers.queue.connection', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SLOGGER_DISPATCHER_QUEUE_CONNECTION');

        $this->config->getConnection();
    }

    public function testGetConnectionFailsFastWhenEmpty(): void
    {
        config()->set('slogger.dispatchers.queue.connection', '');

        $this->expectException(RuntimeException::class);

        $this->config->getConnection();
    }

    public function testGetSocketClientTimeoutSecondsReturnsConfiguredValue(): void
    {
        config()->set('slogger.dispatchers.queue.api_clients.socket.timeout_seconds', '25');

        self::assertSame(25, $this->config->getSocketClientTimeoutSeconds());
    }

    public function testGetSocketClientTimeoutSecondsFallsBackToDefault(): void
    {
        // an application with a config published before the key existed
        config()->set('slogger.dispatchers.queue.api_clients.socket.timeout_seconds', null);

        self::assertSame(
            DispatcherQueueConfig::DEFAULT_SOCKET_CLIENT_TIMEOUT_SECONDS,
            $this->config->getSocketClientTimeoutSeconds()
        );
    }

    public function testGetSocketClientTimeoutSecondsIgnoresNonPositiveValue(): void
    {
        config()->set('slogger.dispatchers.queue.api_clients.socket.timeout_seconds', 0);

        self::assertSame(
            DispatcherQueueConfig::DEFAULT_SOCKET_CLIENT_TIMEOUT_SECONDS,
            $this->config->getSocketClientTimeoutSeconds()
        );
    }
}
