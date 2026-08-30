<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Concurrency;

use Closure;
use Fiber;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;

/**
 * What a coroutine runtime does to this package, made deterministic.
 *
 * PHP has fibers of its own, so the interleaving these tests are about needs neither
 * the host application nor its runtime: each unit of work runs in a fiber and says
 * where it yields, and the order is fixed rather than raced.
 */
abstract class BaseConcurrencyTestCase extends BaseWatcherTestCase
{
    /**
     * The fiber store is not the default - a package that runs one unit of work at a
     * time has no use for it - so these ask for it the way an application would.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('slogger.context', 'fiber');
    }

    /**
     * Starts every unit, then resumes them in turn until all have finished.
     *
     * @param list<Closure(): void> $units
     */
    protected function interleave(array $units): void
    {
        $fibers = array_map(
            static fn(Closure $unit): Fiber => new Fiber($unit),
            $units
        );

        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        while (true) {
            $resumed = false;

            foreach ($fibers as $fiber) {
                if (!$fiber->isSuspended()) {
                    continue;
                }

                $fiber->resume();

                $resumed = true;
            }

            if (!$resumed) {
                return;
            }
        }
    }
}
