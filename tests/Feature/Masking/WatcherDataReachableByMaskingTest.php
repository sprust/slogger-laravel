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
 * The masker skips the top level of a trace's data, so a watcher that puts the
 * application's own data there puts it out of reach.
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
        // is nested under it
        self::assertSame(
            MaskHelper::FULL_MASK,
            $masked['cache']['user:1:api_token']['value']
        );

        // the key stays readable: it is an identifier, and already a tag
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

        // as a value, as an array key and as a tag. Only the first was ever masked:
        // no key names a key, and none names a tag
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

    public function testTheStructuralNamesOfAWatcherStayReadable(): void
    {
        // `name` was a shipped partial key, and a key list matches word components
        // too, so every one of these came out mangled
        $data = [
            'job' => [
                // JobWatcher::formatJobData()
                'name' => 'App\\Jobs\\SendEmail',
                'data' => [
                    'queue_name' => 'emails',
                ],
            ],
            // EventWatcher::formatListeners()
            'listeners' => [
                ['name' => 'App\\Listeners\\SendWelcomeMail', 'queued' => true],
            ],
            'files' => [
                'avatar' => ['name' => 'photo.jpg', 'size' => 1024],
            ],
            // and the absurd shape: `name` names the value beside it
            'settings' => [
                ['name' => 'password', 'value' => 'hunter2'],
            ],
        ];

        $masked = $this->mask($data);

        self::assertSame('App\\Jobs\\SendEmail', $masked['job']['name']);
        self::assertSame('emails', $masked['job']['data']['queue_name']);
        self::assertSame('App\\Listeners\\SendWelcomeMail', $masked['listeners'][0]['name']);
        self::assertSame('photo.jpg', $masked['files']['avatar']['name']);
        self::assertSame('password', $masked['settings'][0]['name']);
    }

    public function testAPersonsNameIsStillMaskedWhereItIsSpeltOut(): void
    {
        $data = [
            'context' => [
                'first_name'  => 'Johnathan',
                'last_name'   => 'Doelittle',
                'middle_name' => 'Archibald',
                'full_name'   => 'Johnathan Doelittle',
                'username'    => 'johnathan',
                'order_id'    => 42,
            ],
        ];

        $masked = $this->mask($data);

        foreach (['first_name', 'last_name', 'middle_name', 'full_name', 'username'] as $key) {
            self::assertNotSame(
                $data['context'][$key],
                $masked['context'][$key],
                "$key was shipped as it is"
            );
        }

        // still an identifier, not a person
        self::assertSame(42, $masked['context']['order_id']);
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
