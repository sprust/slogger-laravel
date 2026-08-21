<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class MaskHelperTest extends BaseTestCase
{
    public function testMaskValueKeepsFalsyValues(): void
    {
        self::assertNull(MaskHelper::maskValue(null));
        self::assertSame('', MaskHelper::maskValue(''));
        self::assertSame(0, MaskHelper::maskValue(0));
        self::assertSame('0', MaskHelper::maskValue('0'));
        self::assertFalse(MaskHelper::maskValue(false));
    }

    public function testMaskValueHandlesTypes(): void
    {
        self::assertFalse(MaskHelper::maskValue(true));
        self::assertSame(0, MaskHelper::maskValue(123));
        self::assertSame(0.0, MaskHelper::maskValue(1.23));

        $stringable = new class {
            public function __toString(): string
            {
                return 'secret';
            }
        };

        $maskedStringable = MaskHelper::maskValue($stringable);

        self::assertIsString($maskedStringable);
        self::assertNotSame('secret', $maskedStringable);

        self::assertSame('********', MaskHelper::maskValue(['secret']));
        self::assertSame('********', MaskHelper::maskValue((object) ['a' => 'b']));
    }

    public function testMaskValueMasksStringsByLength(): void
    {
        self::assertSame('*', MaskHelper::maskValue('a'));
        self::assertSame('a*', MaskHelper::maskValue('ab'));
        self::assertSame('a*c', MaskHelper::maskValue('abc'));
        self::assertSame('ab**ef', MaskHelper::maskValue('abcdef'));
    }

    public function testMaskArrayByKeysMatchesKeySubstringsCaseInsensitively(): void
    {
        $data = [
            'API_KEY'  => 'key-1',
            'user'     => [
                'lastName'  => 'Ivanov',
                'phone'     => '+70000000000',
                'is_active' => true,
            ],
            'file_size' => 100,
        ];

        $masked = MaskHelper::maskArrayByKeys($data, ['api_key', 'lastname', 'phone']);

        self::assertNotSame('key-1', $masked['API_KEY']);
        self::assertNotSame('Ivanov', $masked['user']['lastName']);
        self::assertNotSame('+70000000000', $masked['user']['phone']);

        // untouched: nothing in their keys matches
        self::assertTrue($masked['user']['is_active']);
        self::assertSame(100, $masked['file_size']);
    }

    public function testMaskArrayByKeysMatchesAnySegmentOfTheDottedKey(): void
    {
        $data = [
            'job' => [
                'data' => [
                    'customer_email' => 'a@b.test',
                ],
            ],
            'auth' => [
                'method'  => 'oauth',
                'expires' => 100,
            ],
        ];

        $masked = MaskHelper::maskArrayByKeys($data, ['email', 'auth']);

        self::assertNotSame('a@b.test', $masked['job']['data']['customer_email']);

        // the whole subtree of a matching key is masked
        self::assertNotSame('oauth', $masked['auth']['method']);
        self::assertNotSame(100, $masked['auth']['expires']);
    }

    public function testMaskArrayByKeysSkipsExceptedKeys(): void
    {
        $data = [
            'connection_name' => 'redis',
            'job'             => [
                'data' => [
                    'file_name' => 'document.pdf',
                ],
            ],
        ];

        $masked = MaskHelper::maskArrayByKeys(
            data: $data,
            keys: ['_name'],
            exceptedKeyPatterns: ['connection_name']
        );

        // the package's own key describes the trace, not the traced data
        self::assertSame('redis', $masked['connection_name']);

        self::assertNotSame('document.pdf', $masked['job']['data']['file_name']);
    }

    public function testMaskArrayByKeysWithoutKeysKeepsDataIntact(): void
    {
        $data = ['token' => 'keep-me'];

        self::assertSame($data, MaskHelper::maskArrayByKeys($data, []));
    }
}
