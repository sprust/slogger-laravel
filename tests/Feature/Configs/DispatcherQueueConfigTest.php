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
}
