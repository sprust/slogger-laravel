<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Context;

use Fiber;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SLoggerLaravel\Context\FiberTraceContext;
use WeakMap;

class FiberTraceContextTest extends TestCase
{
    /**
     * With no fiber running there is nothing to keep apart, and this has to behave
     * exactly like the array store - that is what makes it safe as the default.
     */
    public function testWithoutAFiberItIsASingleStore(): void
    {
        $context = new FiberTraceContext();

        self::assertSame('fallback', $context->get('slogger.absent', 'fallback'));

        $context->set('slogger.key', 'value');

        self::assertSame('value', $context->get('slogger.key'));

        $context->set('slogger.key', null);

        self::assertNull($context->get('slogger.key', 'fallback'));
    }

    public function testTwoFibersDoNotSeeEachOthersValues(): void
    {
        $context = new FiberTraceContext();

        $read = [];

        $unit = static function (string $name) use ($context, &$read): void {
            $context->set('slogger.key', $name);

            Fiber::suspend();

            // resumed after the other one wrote its own
            $read[$name] = $context->get('slogger.key');
        };

        $first  = new Fiber($unit);
        $second = new Fiber($unit);

        $first->start('first');
        $second->start('second');

        $first->resume();
        $second->resume();

        self::assertSame(['first' => 'first', 'second' => 'second'], $read);
    }

    public function testAFiberStartsWithNothingOfItsOwn(): void
    {
        $context = new FiberTraceContext();

        $context->set('slogger.key', 'written outside');

        $seen = 'unset';

        $fiber = new Fiber(
            static function () use ($context, &$seen): void {
                $seen = $context->get('slogger.key', 'nothing here');
            }
        );

        $fiber->start();

        // documented, not accidental: reads do not fall through to whatever created
        // this fiber, because PHP cannot say what that was
        self::assertSame('nothing here', $seen);

        // and the value outside is left alone
        self::assertSame('written outside', $context->get('slogger.key'));
    }

    /**
     * Nothing has to be released by hand: the map is weakly keyed, so it goes when the
     * fiber does. Without this a process that never restarts would accumulate one map
     * per request it ever served.
     */
    public function testTheMapOfAFiberIsReleasedWithIt(): void
    {
        $context = new FiberTraceContext();

        $fiber = new Fiber(
            static function () use ($context): void {
                $context->set('slogger.key', 'from a fiber');

                Fiber::suspend();
            }
        );

        $fiber->start();

        self::assertCount(1, $this->fiberValuesOf($context));

        // dropped while still suspended, which is a unit of work that never finished
        unset($fiber);

        gc_collect_cycles();

        self::assertCount(0, $this->fiberValuesOf($context));
    }

    /**
     * @return WeakMap<object, array<string, mixed>>
     */
    private function fiberValuesOf(FiberTraceContext $context): WeakMap
    {
        /** @var WeakMap<object, array<string, mixed>> $values */
        $values = (new ReflectionProperty(FiberTraceContext::class, 'fiberValues'))->getValue($context);

        return $values;
    }
}
