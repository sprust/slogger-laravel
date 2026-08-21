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

    public function testMaskValueMasksASingleMultibyteCharacter(): void
    {
        // strlen() counts bytes, so a two-byte character used to slip through unmasked
        self::assertSame('*', MaskHelper::maskValue('é'));
    }

    public function testMaskArrayByKeysLeavesTheTopLevelAlone(): void
    {
        $data = [
            // the watcher's own structure: `connection_name` contains `_name` and
            // `token_count` contains `token`, but neither is traced data
            'connection_name' => 'redis',
            'token_count'     => 3,
            'job'             => [
                'data' => [
                    'customer_email' => 'a@b.test',
                ],
            ],
        ];

        $masked = MaskHelper::maskArrayByKeys($data, ['_name', 'token', 'email']);

        self::assertSame('redis', $masked['connection_name']);
        self::assertSame(3, $masked['token_count']);

        self::assertNotSame('a@b.test', $masked['job']['data']['customer_email']);
    }

    public function testMaskArrayByKeysMatchesKeySubstringsCaseInsensitively(): void
    {
        $data = [
            'context' => [
                'API_KEY'   => 'key-1',
                'user'      => [
                    'lastName' => 'Ivanov',
                    'phone'    => '+70000000000',
                ],
                'is_active' => true,
                'file_size' => 100,
            ],
        ];

        $masked = MaskHelper::maskArrayByKeys($data, ['api_key', 'lastname', 'phone']);

        self::assertNotSame('key-1', $masked['context']['API_KEY']);
        self::assertNotSame('Ivanov', $masked['context']['user']['lastName']);
        self::assertNotSame('+70000000000', $masked['context']['user']['phone']);

        // untouched: nothing in their keys matches
        self::assertTrue($masked['context']['is_active']);
        self::assertSame(100, $masked['context']['file_size']);
    }

    public function testMaskArrayByKeysMasksTheSubtreeOfAMatchingKey(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            [
                'context' => [
                    'auth' => [
                        'method'  => 'oauth',
                        'expires' => 100,
                    ],
                ],
            ],
            ['auth']
        );

        self::assertNotSame('oauth', $masked['context']['auth']['method']);
        self::assertNotSame(100, $masked['context']['auth']['expires']);
    }

    public function testMaskArrayByKeysKeepsKeysThatContainDots(): void
    {
        $data = [
            'response' => [
                'body' => [
                    'user.city'  => 'Berlin',
                    'user.email' => 'a@b.test',
                ],
            ],
        ];

        $masked = MaskHelper::maskArrayByKeys($data, ['email']);

        // flattening and rebuilding would turn `user.city` into a nested array
        self::assertSame('Berlin', $masked['response']['body']['user.city'] ?? null);

        self::assertArrayHasKey('user.email', $masked['response']['body']);
        self::assertNotSame('a@b.test', $masked['response']['body']['user.email']);
    }

    public function testMaskArrayByKeysKeepsASiblingCollidingWithADottedKey(): void
    {
        $data = [
            'context' => [
                'a'   => 'scalar',
                'a.b' => 'other',
            ],
        ];

        // neither key may swallow the other
        self::assertSame($data, MaskHelper::maskArrayByKeys($data, ['token']));
    }

    public function testMaskArrayByKeysMasksListsElementWise(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['phones' => ['+70000000001', '+70000000002']]],
            ['phone']
        );

        self::assertCount(2, $masked['context']['phones']);

        foreach ($masked['context']['phones'] as $phone) {
            self::assertIsString($phone);
            self::assertStringContainsString('*', $phone);
        }
    }

    public function testMaskArrayByKeysWithoutKeysKeepsDataIntact(): void
    {
        $data = ['context' => ['token' => 'keep-me']];

        self::assertSame($data, MaskHelper::maskArrayByKeys($data, []));
    }

    public function testMaskArrayByKeysLooksInsideJsonStrings(): void
    {
        // an Eloquent `array` cast hands the whole document over as a string, and
        // `meta` says nothing about what is inside it
        $masked = MaskHelper::maskArrayByKeys(
            [
                'changes' => [
                    'meta' => '{"customer_email":"c@d.test","order_id":43}',
                ],
            ],
            ['email']
        );

        $decoded = json_decode($masked['changes']['meta'], true);

        self::assertIsArray($decoded);
        self::assertNotSame('c@d.test', $decoded['customer_email']);
        self::assertSame(43, $decoded['order_id']);
    }

    public function testMaskArrayByKeysMasksInsideNestedJsonStrings(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            [
                'context' => [
                    'outer' => '{"inner":"{\"token\":\"t-1\"}"}',
                ],
            ],
            ['token']
        );

        self::assertStringNotContainsString('t-1', $masked['context']['outer']);
    }

    public function testMaskArrayByKeysKeepsAJsonStringByteForByteWhenNothingMatches(): void
    {
        $json = '{ "order_id" : 43, "url": "a\/b", "ru": "\u0410" }';

        $masked = MaskHelper::maskArrayByKeys(['changes' => ['meta' => $json]], ['email']);

        // re-encoding would drop the spacing, the escaped slash and the escaped
        // character, so an untouched document is left exactly as it came in
        self::assertSame($json, $masked['changes']['meta']);
    }

    public function testMaskArrayByKeysLeavesStringsThatOnlyLookLikeJson(): void
    {
        $data = [
            'context' => [
                'note'  => '{not json at all',
                'plain' => 'nothing to see',
            ],
        ];

        self::assertSame($data, MaskHelper::maskArrayByKeys($data, ['email', 'token']));
    }

    public function testMaskArrayByKeysMasksAJsonStringWholeWhenItsOwnKeyMatches(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['auth_payload' => '{"a":1}']],
            ['auth']
        );

        // the key itself is flagged, so the value is masked as a value, not parsed
        self::assertNotSame('{"a":1}', $masked['context']['auth_payload']);
        self::assertNull(json_decode($masked['context']['auth_payload'], true));
    }
}
