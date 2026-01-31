<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature;

use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use SLoggerLaravel\ServiceProvider;

abstract class BaseTestCase extends TestCase
{
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }
}
