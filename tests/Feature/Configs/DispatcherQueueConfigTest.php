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

    public function testDirectSocketClientUsesConfiguredUrl(): void
    {
        config()->set('slogger.dispatchers.queue.api_clients.socket.type', 'direct');
        config()->set('slogger.dispatchers.queue.api_clients.socket.url', 'tcp://slogger:9002');

        self::assertSame('tcp://slogger:9002', $this->config->getSocketClientAddress());
    }

    public function testProxySocketClientUsesNormalizedFixedUnixSocket(): void
    {
        config()->set('slogger.dispatchers.queue.api_clients.socket.type', 'proxy');
        config()->set('slogger.dispatchers.queue.api_clients.socket.url', null);

        $expected = 'unix://' . base_path('storage/slogger/traces/local-proxy.sock');

        foreach (['/storage/slogger/traces/', 'storage/slogger/traces'] as $path) {
            config()->set('slogger.local_proxy.traces_path', $path);

            self::assertSame($expected, $this->config->getSocketClientAddress());
        }
    }

    public function testUnknownSocketClientTypeFails(): void
    {
        config()->set('slogger.dispatchers.queue.api_clients.socket.type', 'unknown');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('direct or proxy');

        $this->config->getSocketClientAddress();
    }

    public function testLocalProxyPathRejectsParentTraversal(): void
    {
        config()->set('slogger.dispatchers.queue.api_clients.socket.type', 'proxy');
        config()->set('slogger.local_proxy.traces_path', 'storage/../traces');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("must not contain '..'");

        $this->config->getSocketClientAddress();
    }
}
