<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Context;

use Illuminate\Contracts\Container\BindingResolutionException;
use RuntimeException;
use SLoggerLaravel\Context\ArrayTraceContext;
use SLoggerLaravel\Context\FiberTraceContext;
use SLoggerLaravel\Context\TraceContextFactory;
use SLoggerLaravel\Context\TraceContextInterface;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class TraceContextFactoryTest extends BaseTestCase
{
    /**
     * @throws BindingResolutionException
     */
    public function testItBuildsTheTwoStoresThatShipHere(): void
    {
        $factory = $this->getApp()->make(TraceContextFactory::class);

        self::assertInstanceOf(FiberTraceContext::class, $factory->create('fiber'));
        self::assertInstanceOf(ArrayTraceContext::class, $factory->create('array'));
    }

    /**
     * The extension point: an application that knows how to name its own coroutines
     * puts the class in `slogger.context` and touches no provider.
     *
     * @throws BindingResolutionException
     */
    public function testItBuildsAStoreTheApplicationBroughtItself(): void
    {
        $factory = $this->getApp()->make(TraceContextFactory::class);

        self::assertInstanceOf(
            OwnTraceContext::class,
            $factory->create(OwnTraceContext::class)
        );
    }

    /**
     * @throws BindingResolutionException
     */
    public function testItRefusesANameThatIsNotAStore(): void
    {
        $factory = $this->getApp()->make(TraceContextFactory::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown trace context [nonsense]');

        $factory->create('nonsense');
    }

    /**
     * A class that exists but is not a store is refused just the same, rather than
     * being handed back for the whole package to call methods on.
     *
     * @throws BindingResolutionException
     */
    public function testItRefusesAClassThatDoesNotImplementTheContract(): void
    {
        $factory = $this->getApp()->make(TraceContextFactory::class);

        $this->expectException(RuntimeException::class);

        $factory->create(self::class);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testTheContainerHandsOutWhatTheConfigNames(): void
    {
        config()->set('slogger.context', 'array');

        $app = $this->getApp();

        $app->forgetInstance(TraceContextInterface::class);

        self::assertInstanceOf(
            ArrayTraceContext::class,
            $app->make(TraceContextInterface::class)
        );
    }
}

class OwnTraceContext implements TraceContextInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value): void
    {
    }
}
