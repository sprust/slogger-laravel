<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class MaskHelperTest extends BaseTestCase
{
    public function testMaskArrayByListIsCaseInsensitive(): void
    {
        $data = [
            'Authorization' => 'Bearer token',
            'X-Request-Id'  => 'id-123',
        ];

        $masked = MaskHelper::maskArrayByList($data, ['authorization']);

        self::assertNotSame('Bearer token', $masked['Authorization']);
        self::assertSame('id-123', $masked['X-Request-Id']);
    }

    public function testMaskArrayByPatternsMasksNestedKeys(): void
    {
        $data = [
            'user' => [
                'token'   => 'secret-token',
                'profile' => [
                    'password' => 'secret-pass',
                ],
            ],
            'safe' => 'ok',
        ];

        $masked = MaskHelper::maskArrayByPatterns($data, ['*.token', '*.password']);

        self::assertNotSame('secret-token', $masked['user']['token']);
        self::assertNotSame('secret-pass', $masked['user']['profile']['password']);
        self::assertSame('ok', $masked['safe']);
    }

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
}
