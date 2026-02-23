<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Items;

use Illuminate\Contracts\Container\BindingResolutionException;
use RuntimeException;
use SLoggerLaravel\Dispatcher\Items\DispatcherFactory;
use SLoggerLaravel\Dispatcher\Items\Memory\MemoryDispatcher;
use SLoggerLaravel\Dispatcher\Items\Queue\QueueDispatcher;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class DispatcherFactoryTest extends BaseTestCase
{
    private DispatcherFactory $factory;

    /**
     * @throws BindingResolutionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->getApp()->make(DispatcherFactory::class);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCreateReturnsDispatcherInstances(): void
    {
        self::assertInstanceOf(MemoryDispatcher::class, $this->factory->create('memory'));
        self::assertInstanceOf(QueueDispatcher::class, $this->factory->create('queue'));
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCreateThrowsOnUnknownDispatcher(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown dispatcher: unknown');

        $this->factory->create('unknown');
    }
}
