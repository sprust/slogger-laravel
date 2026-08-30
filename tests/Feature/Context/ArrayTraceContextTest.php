<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Context;

use PHPUnit\Framework\TestCase;
use SLoggerLaravel\Context\ArrayTraceContext;

class ArrayTraceContextTest extends TestCase
{
    public function testTheDefaultIsGivenBackOnlyForAKeyThatIsNotThere(): void
    {
        $context = new ArrayTraceContext();

        self::assertSame('fallback', $context->get('slogger.absent', 'fallback'));
        self::assertNull($context->get('slogger.absent'));
    }

    /**
     * A key holding null is a key that is there. The difference matters to anything
     * that means "no parent" rather than "never set".
     */
    public function testAStoredNullIsNotTheDefault(): void
    {
        $context = new ArrayTraceContext();

        $context->set('slogger.trace.parent_id', null);

        self::assertNull($context->get('slogger.trace.parent_id', 'fallback'));
    }

    public function testAValueIsReadBackAndThenReplaced(): void
    {
        $context = new ArrayTraceContext();

        $context->set('slogger.key', 'first');

        self::assertSame('first', $context->get('slogger.key'));

        $context->set('slogger.key', 'second');

        self::assertSame('second', $context->get('slogger.key'));
    }

    /**
     * The whole point of the class: one array for the process, so what one unit of
     * work writes the next one reads.
     */
    public function testStateIsSharedByEverythingTheProcessRuns(): void
    {
        $context = new ArrayTraceContext();

        $fiber = new \Fiber(
            static function () use ($context): void {
                $context->set('slogger.key', 'written inside a fiber');
            }
        );

        $fiber->start();

        self::assertSame('written inside a fiber', $context->get('slogger.key'));
    }
}
