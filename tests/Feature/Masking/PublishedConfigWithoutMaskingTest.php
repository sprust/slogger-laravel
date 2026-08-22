<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Masking;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use SLoggerLaravel\Configs\MaskingConfig;
use SLoggerLaravel\ServiceProvider;
use SLoggerLaravel\Watchers\Children\ModelWatcher;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;
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
        // the shape a real pre-1.3 config has: the sections live inside the watcher's
        // own entry in the `watchers` list, not under any path config() can address
        $this->getApp()['config']->set('slogger.watchers', [
            [
                'class'   => RequestWatcher::class,
                'enabled' => true,
                'config'  => [
                    'input' => [
                        'headers_masking'    => ['*' => ['authorization']],
                        'parameters_masking' => ['*' => ['*ssn*']],
                    ],
                    'output' => [
                        'fields_masking' => ['*' => ['*iban*']],
                    ],
                ],
            ],
            [
                'class'   => ModelWatcher::class,
                'enabled' => true,
                'config'  => [
                    'masks' => ['*' => ['*token*']],
                ],
            ],
        ]);

        $reported = null;

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message) use (&$reported): bool {
                $reported = $message;

                return true;
            });

        (new ServiceProvider($this->getApp()))->boot();

        self::assertIsString($reported);

        // every section an application could have added keys to, not just the one
        // that happens to be easy to reach
        foreach (
            [
                'config.input.headers_masking',
                'config.input.parameters_masking',
                'config.output.fields_masking',
                'config.masks',
            ] as $section
        ) {
            self::assertStringContainsString($section, $reported);
        }

        // and not the one that is absent from this config
        self::assertStringNotContainsString('config.output.headers_masking', $reported);
    }

    public function testAConfigWithoutRetiredSectionsSaysNothing(): void
    {
        // the config this package ships today: the sections are gone from it
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
