<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/**
 * The entry script defines this where the process starts, and a test suite has no entry
 * script - so the tests that are about it would have nothing to look at.
 *
 * Deliberately long ago, because that is what it means in a server that boots once and
 * then serves for hours, which is the case worth testing. Nothing else reads it, not the
 * framework and not testbench, so a stale value costs the rest of the suite nothing.
 *
 * @see \SLoggerLaravel\Watchers\Parents\RequestWatcher::claimLaravelStart()
 */
define('LARAVEL_START', microtime(true) - 7700);
