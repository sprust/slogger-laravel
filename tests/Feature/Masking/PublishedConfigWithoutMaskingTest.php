<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Masking;

use Illuminate\Support\Arr;
use SLoggerLaravel\Configs\MaskingConfig;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

/**
 * A published config replaces the package's own, so an application that published one
 * before the masking section existed would silently run without any masking at all.
 * The defaults are therefore read from the package's own config file when a section is
 * missing - which also holds while the application's config cache is stale.
 */
class PublishedConfigWithoutMaskingTest extends BaseTestCase
{
    public function testTheDefaultsSurviveAMissingSection(): void
    {
        $this->forgetMaskingSection();

        $config = new MaskingConfig();

        self::assertSame($this->shippedMasking()['full_keys'], $config->getFullKeys());
        self::assertSame($this->shippedMasking()['partial_keys'], $config->getPartialKeys());
        self::assertSame(
            array_values($this->shippedMasking()['value_patterns']),
            $config->getValuePatterns()
        );
    }

    public function testAnExplicitEmptyListStillTurnsMaskingOff(): void
    {
        $this->getApp()['config']->set('slogger.masking.full_keys', []);

        self::assertSame([], (new MaskingConfig())->getFullKeys());

        // the other lists are untouched: clearing one must not clear them all
        self::assertNotSame([], (new MaskingConfig())->getPartialKeys());
        self::assertNotSame([], (new MaskingConfig())->getValuePatterns());
    }

    public function testAMisconfiguredListDoesNotTakeTheBatchDown(): void
    {
        // this is read inside the dispatcher job, where a TypeError costs the batch
        // and every retry of it
        $this->getApp()['config']->set('slogger.masking.full_keys', 'token');

        self::assertSame(['token'], (new MaskingConfig())->getFullKeys());

        $this->getApp()['config']->set('slogger.masking.full_keys', ['token', 42, '', null]);

        self::assertSame(['token'], (new MaskingConfig())->getFullKeys());
    }

    /**
     * The package's config file is the single source of the defaults - there is no
     * second copy in code to drift away from it.
     *
     * @return array<string, mixed>
     */
    private function shippedMasking(): array
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/slogger.php';

        /** @var array<string, mixed> $masking */
        $masking = $config['masking'];

        return $masking;
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
