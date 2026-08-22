<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Masking;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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

        // the file-level fallback, which holds even while the config cache is stale:
        // `mergeConfigFrom` is a no-op for a cached configuration
        $config = new MaskingConfig();

        self::assertSame($this->shippedMasking()['full_keys'], $config->getFullKeys());
        self::assertSame($this->shippedMasking()['partial_keys'], $config->getPartialKeys());
    }

    public function testRegisterMergesTheSectionBack(): void
    {
        $this->forgetMaskingSection();

        self::assertNull(config('slogger.masking'));

        (new ServiceProvider($this->getApp()))->register();

        self::assertSame($this->shippedMasking(), config('slogger.masking'));
    }

    public function testAnExplicitEmptyListStillTurnsMaskingOff(): void
    {
        $this->getApp()['config']->set('slogger.masking.full_keys', []);

        self::assertSame([], (new MaskingConfig())->getFullKeys());

        // the other list is untouched: clearing one must not clear both
        self::assertNotSame([], (new MaskingConfig())->getPartialKeys());
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

    public function testARetiredMaskingSectionIsReportedOutLoud(): void
    {
        // an application that added its own keys there stopped masking them on
        // upgrade; a README is not where anyone will look for that
        $this->getApp()['config']->set(
            'slogger.watchers_config.requests.input.parameters_masking',
            ['*' => ['*ssn*']]
        );

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static function (string $message): bool {
                return str_contains($message, 'no longer read')
                    && str_contains($message, 'parameters_masking');
            });

        (new ServiceProvider($this->getApp()))->boot();
    }

    public function testAConfigWithoutRetiredSectionsSaysNothing(): void
    {
        Log::shouldReceive('channel')->never();

        (new ServiceProvider($this->getApp()))->boot();
    }

    /**
     * The package's config file is the single source of the defaults - there is no
     * second copy of the lists in code to drift away from it.
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
