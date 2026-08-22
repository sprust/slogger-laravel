<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Masking;

use Illuminate\Support\Facades\Cache;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Children\CacheWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

/**
 * The masker skips the top level of a trace's data, because that level belongs to the
 * watcher. A watcher that puts the application's own data there puts it out of reach:
 * these are the shapes that used to do exactly that.
 */
class WatcherDataReachableByMaskingTest extends BaseWatcherTestCase
{
    public function testACachedSecretIsReachableByItsCacheKey(): void
    {
        $this->registerWatcher(JobWatcher::class, null);
        $this->registerWatcher(CacheWatcher::class, null);

        dispatch(static function (): void {
            Cache::put('user:1:api_token', 'tok-abcdefghijklmnop', 60);
        });

        $creating = $this->dispatcher->findCreating(type: 'cache', tag: 'set');

        self::assertCount(1, $creating);

        $data = $creating[0]->data;

        // the traced application does not mask
        self::assertSame(
            'tok-abcdefghijklmnop',
            $data['cache']['user:1:api_token']['value'] ?? null
        );

        $masked = $this->mask($data);

        // the cache key is the only thing that says what the value is, so the value
        // is nested under it and the key becomes part of the matched path
        self::assertSame(
            MaskHelper::FULL_MASK,
            $masked['cache']['user:1:api_token']['value']
        );

        // the key itself stays readable: it is an identifier, and it is already a tag
        self::assertSame('user:1:api_token', $masked['key']);
    }

    public function testAnOrdinaryCachedValueIsLeftAlone(): void
    {
        $this->registerWatcher(JobWatcher::class, null);
        $this->registerWatcher(CacheWatcher::class, null);

        dispatch(static function (): void {
            Cache::put('orders:page:2', 'some-payload', 60);
        });

        $creating = $this->dispatcher->findCreating(type: 'cache', tag: 'set');

        self::assertCount(1, $creating);

        $masked = $this->mask($creating[0]->data);

        self::assertSame(
            'some-payload',
            $masked['cache']['orders:page:2']['value']
        );
    }

    public function testACacheKeyIsMaskedInBothPositionsItAppears(): void
    {
        $this->registerWatcher(JobWatcher::class, null);
        $this->registerWatcher(CacheWatcher::class, null);

        dispatch(static function (): void {
            Cache::put('otp:john.doe@example.com', '123456', 60);
        });

        $creating = $this->dispatcher->findCreating(type: 'cache', tag: 'set');

        self::assertCount(1, $creating);

        $masker = $this->getApp()->make(TraceDataMasker::class);

        $masked = $masker->mask($creating[0]->data);
        $tags   = $masker->maskTags($creating[0]->tags);

        // the same string appears as a value, as an array key, and as a tag. Only
        // the first was ever masked - a key has no key naming it, and a tag has none
        // either, so the key lists cannot reach them. A value pattern can
        self::assertSame('otp:jo****************om', $masked['key']);
        self::assertSame(['otp:jo****************om'], array_keys($masked['cache']));
        self::assertSame(['set', 'otp:jo****************om'], $tags);
    }

    public function testAnAddressInAUrlTagIsMasked(): void
    {
        $masker = $this->getApp()->make(TraceDataMasker::class);

        self::assertSame(
            ['/users/jo****************om/orders'],
            $masker->maskTags(['/users/john.doe@example.com/orders'])
        );
    }

    public function testMailAddressesAreReachableByName(): void
    {
        // the mail watcher's own shape, built the way handleMessageSent() builds it
        $data = [
            'mailable' => 'App\\Mail\\Welcome',
            'queued'   => false,
            'message'  => [
                'to' => [
                    [
                        'email'     => 'john.doe@example.com',
                        'full_name' => 'John Doe',
                    ],
                ],
                'subject' => 'Welcome',
            ],
        ];

        $masked = $this->mask($data);

        // an address identifies rather than authenticates: partially masked
        self::assertSame('jo****************om', $masked['message']['to'][0]['email']);
        self::assertSame('Jo****oe', $masked['message']['to'][0]['full_name']);

        // not a person: left readable
        self::assertSame('Welcome', $masked['message']['subject']);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function mask(array $data): array
    {
        return $this->getApp()->make(TraceDataMasker::class)->mask($data);
    }
}
