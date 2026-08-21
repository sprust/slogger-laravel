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
    public function testTheDefaultsSurviveAMissingSection(): void
    {
        $this->forgetMaskingSection();

        // the code-level fallback, which holds even while the config cache is stale:
        // `mergeConfigFrom` is a no-op for a cached configuration
        $config = new MaskingConfig();

        self::assertSame(MaskingConfig::DEFAULT_KEYS, $config->getKeys());
    }

    public function testRegisterMergesTheSectionBack(): void
    {
        $this->forgetMaskingSection();

        self::assertNull(config('slogger.masking'));

        (new ServiceProvider($this->getApp()))->register();

        self::assertSame(MaskingConfig::DEFAULT_KEYS, config('slogger.masking.keys'));
    }

    public function testTheShippedConfigMatchesTheDefaults(): void
    {
        // the published file is what a user edits, the constants are the fallback;
        // they must not drift apart
        self::assertSame(MaskingConfig::DEFAULT_KEYS, config('slogger.masking.keys'));
    }

    public function testAnExplicitEmptyListStillTurnsMaskingOff(): void
    {
        $this->getApp()['config']->set('slogger.masking.keys', []);

        self::assertSame([], (new MaskingConfig())->getKeys());
    }

    private function forgetMaskingSection(): void
    {
        $app = $this->getApp();

        $app['config']->set(
            'slogger',
            Arr::except($app['config']->get('slogger'), ['masking'])
        );
    }
}
