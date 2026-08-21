<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Masking;

use Illuminate\Support\Arr;
use SLoggerLaravel\Configs\MaskingConfig;
use SLoggerLaravel\ServiceProvider;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

/**
 * A published config replaces the package's own, so an application that published one
 * before the masking section existed would silently run without any masking at all.
 */
class PublishedConfigWithoutMaskingTest extends BaseTestCase
{
    public function test(): void
    {
        $app = $this->getApp();

        $app['config']->set(
            'slogger',
            Arr::except($app['config']->get('slogger'), ['masking'])
        );

        self::assertSame([], (new MaskingConfig())->getKeys());

        (new ServiceProvider($app))->register();

        $config = new MaskingConfig();

        self::assertContains('token', $config->getKeys());
        self::assertContains('connection_name', $config->getExceptedKeys());
    }
}
