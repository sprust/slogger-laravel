<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\MaskingRules;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class MaskHelperTest extends BaseTestCase
{
    private const EMAIL_PATTERN = '/[\\w.+-]+@[\\w-]+\\.[\\w.-]*[\\w-]/u';

    public function testMaskValueKeepsWhatCannotHideAnything(): void
    {
        self::assertNull(MaskHelper::maskValue(null));
        self::assertSame('', MaskHelper::maskValue(''));
        self::assertSame(0, MaskHelper::maskValue(0));
        self::assertFalse(MaskHelper::maskValue(false));
    }

    public function testMaskValueMasksAFalsyString(): void
    {
        // a falsy string is still a value: the early return tested truthiness
        self::assertSame(MaskHelper::FULL_MASK, MaskHelper::maskValue('0'));
        self::assertSame('*', MaskHelper::maskValuePartially('0'));
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

    public function testMaskValueLeavesNothingOfAString(): void
    {
        // a token is worthless the moment any of it leaks, and the fixed width hides
        // the length too
        self::assertSame(MaskHelper::FULL_MASK, MaskHelper::maskValue('a'));
        self::assertSame(MaskHelper::FULL_MASK, MaskHelper::maskValue('abcdef'));
        self::assertSame(
            MaskHelper::FULL_MASK,
            MaskHelper::maskValue('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl')
        );
    }

    public function testMaskValuePartiallyKeepsTwoCharactersAtEachEnd(): void
    {
        self::assertSame('jo****************om', MaskHelper::maskValuePartially('john.doe@example.com'));
        self::assertSame('ab**ef', MaskHelper::maskValuePartially('abcdef'));
    }

    public function testMaskValuePartiallyKeepsNothingOfAShortString(): void
    {
        // below six characters, two at each end is most of the value
        self::assertSame('*', MaskHelper::maskValuePartially('a'));
        self::assertSame('**', MaskHelper::maskValuePartially('ab'));
        self::assertSame('*****', MaskHelper::maskValuePartially('abcde'));
    }

    public function testMaskValuePartiallyCountsCharactersNotBytes(): void
    {
        // strlen() counts bytes, so a two-byte character slipped through unmasked
        self::assertSame('*', MaskHelper::maskValuePartially('é'));
        self::assertSame('Ив****ич', MaskHelper::maskValuePartially('Иванович'));
    }

    public function testMaskArrayByKeysPicksTheModeFromTheListTheKeyIsIn(): void
    {
        $data = [
            'context' => [
                'api_token' => 'tok-abcdefghijklmnop',
                'email'     => 'john.doe@example.com',
            ],
        ];

        $masked = MaskHelper::maskArrayByKeys($data, ['*token*'], ['*email*']);

        self::assertSame(MaskHelper::FULL_MASK, $masked['context']['api_token']);
        self::assertSame('jo****************om', $masked['context']['email']);
    }

    public function testMaskArrayByKeysMasksWholeWhenAKeyIsInBothLists(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['email_token' => 'tok-abcdefghij']],
            ['*token*'],
            ['*email*']
        );

        // the stricter list wins; a partial mask on a token is not a mask
        self::assertSame(MaskHelper::FULL_MASK, $masked['context']['email_token']);
    }

    public function testMaskArrayByKeysLooksInsideSerializedArrays(): void
    {
        // a cached session is one serialize() blob holding the CSRF token and the
        // password hash, and it looks like neither JSON nor XML
        $session = serialize([
            '_token'            => 'X7mQabcdefghijklmnopqrstuvwx',
            'password_hash_web' => '$2y$12$abcdefghijkl',
            'locale'            => 'en',
        ]);

        $masked = MaskHelper::maskArrayByKeys(['value' => $session], ['*token*', '*password*']);

        $decoded = unserialize($masked['value'], ['allowed_classes' => false]);

        self::assertSame(MaskHelper::FULL_MASK, $decoded['_token']);
        self::assertSame(MaskHelper::FULL_MASK, $decoded['password_hash_web']);
        self::assertSame('en', $decoded['locale']);
    }

    public function testASerializedBlobHoldingAnObjectIsLeftForTheValuePatterns(): void
    {
        // `allowed_classes: false` turns an object into an incomplete one, which
        // cannot be inspected without throwing - and the throw cost the whole batch
        $blob = serialize([
            'user'  => (object) ['email' => 'john@example.com'],
            'token' => 'X7mQabcdefghijklmnop',
        ]);

        $masked = MaskHelper::maskArrayByKeys(
            ['value' => $blob],
            ['*token*'],
            [],
            [self::EMAIL_PATTERN]
        );

        self::assertIsString($masked['value']);

        // not taken apart - it could not be put back together - but still read as a
        // string, so a pattern match in it does not ship
        self::assertStringNotContainsString('john@example.com', $masked['value']);
        self::assertStringContainsString('jo************om', $masked['value']);
    }

    public function testAnIncompleteObjectIsMaskedRatherThanThrownOn(): void
    {
        // the same object, handed over by a caller that unserialised it itself
        $incomplete = unserialize(
            serialize((object) ['api_token' => 'sk-live-SECRET', 'id' => 7]),
            ['allowed_classes' => false]
        );

        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['dto' => $incomplete]],
            ['*token*']
        );

        self::assertSame(MaskHelper::FULL_MASK, $masked['context']['dto']['api_token']);
        self::assertSame(7, $masked['context']['dto']['id']);

        self::assertStringNotContainsString(
            'sk-live-SECRET',
            json_encode($masked, JSON_THROW_ON_ERROR)
        );

        // and under no key at all: mask() reached for __toString() too
        self::assertSame(MaskHelper::FULL_MASK, MaskHelper::maskValue($incomplete));
    }

    public function testAKeyThatMasksOntoAnExistingOneKeepsBothEntries(): void
    {
        // two keys can mask to the same string, and a payload can already hold
        // something that looks masked. The check only ran when the key had changed
        $masked = MaskHelper::maskArrayByKeys(
            [
                'ctx' => [
                    'jo************om' => 'first',
                    'john@example.com' => 'second',
                ],
            ],
            [],
            [],
            [self::EMAIL_PATTERN]
        );

        self::assertCount(2, $masked['ctx']);
        self::assertContains('first', $masked['ctx']);
        self::assertContains('second', $masked['ctx']);
    }

    public function testAMaskOfZeroIsAMask(): void
    {
        // `array_filter` dropped the mask `"0"` as falsy, so a key called `0` - what
        // json_decode makes of a numeric list - could not be masked at all
        $masked = MaskHelper::maskArrayByKeys(
            ['ctx' => ['0' => 'sk-live-SECRET', '1' => 'kept']],
            ['0']
        );

        self::assertSame(MaskHelper::FULL_MASK, $masked['ctx'][0]);
        self::assertSame('kept', $masked['ctx'][1]);
    }

    public function testADocumentDeeperThanJsonDecodeReadsIsMaskedWhole(): void
    {
        $deep = str_repeat('[', 600) . '"sk-live-SECRET"' . str_repeat(']', 600);

        $masked = MaskHelper::maskArrayByKeys(
            ['ctx' => ['payload' => $deep]],
            ['*token*'],
            [],
            [self::EMAIL_PATTERN]
        );

        // handing it back whole shipped every key in it untouched
        self::assertSame(MaskHelper::FULL_MASK, $masked['ctx']['payload']);
    }

    public function testTheRulesAreCompiledOnceRatherThanPerKey(): void
    {
        $shipped = require __DIR__ . '/../../../config/slogger.php';

        $rules = new MaskingRules(
            $shipped['masking']['full_keys'],
            $shipped['masking']['partial_keys'],
            []
        );

        $payload = [];

        for ($index = 0; $index < 20000; $index++) {
            $payload["field_$index"] = 'value';
        }

        $startedAt = microtime(true);

        MaskHelper::maskArrayByRules(['ctx' => $payload], $rules);

        $elapsed = microtime(true) - $startedAt;

        // ~60 masks per key through Str::is is seconds on a megabyte of JSON. The
        // ceiling is loose: it catches per-key compilation, not a slow machine
        self::assertLessThan(2.0, $elapsed);
    }

    public function testAValuePatternWithAGroupMasksOnlyTheGroup(): void
    {
        $pattern = '/[?&][\w.-]*(?:token|key)[\w.-]*=([^&\s]+)/i';

        $masked = MaskHelper::maskArrayByKeys(
            ['ctx' => ['message' => 'GET https://api.test/v1?api_key=sk_live_SECRET&page=2 failed']],
            [],
            [],
            [$pattern]
        );

        // the name is what makes the line readable, the value what makes it dangerous
        self::assertSame(
            'GET https://api.test/v1?api_key=' . MaskHelper::FULL_MASK . '&page=2 failed',
            $masked['ctx']['message']
        );
    }

    /**
     * Spliced by offset, never by searching the match for the captured text: a
     * credential is routinely equal to the thing that names it - `?password=pass`.
     */
    public function testAValuePatternMasksTheGroupEvenWhenItRepeatsTheNameAroundIt(): void
    {
        $patterns = (require __DIR__ . '/../../../config/slogger.php')['masking']['value_patterns'];

        foreach (
            [
                // a credential equal to the parameter that names it
                'GET /reset?password=pass'  => 'GET /reset?password=' . MaskHelper::FULL_MASK,
                'GET /v1?api_key=key'       => 'GET /v1?api_key=' . MaskHelper::FULL_MASK,
                'GET /cb?code=code&state=x' => 'GET /cb?code=' . MaskHelper::FULL_MASK . '&state=x',

                // and in a url's authority, where the password is often the user
                'could not connect to postgres://postgres:postgres@db:5432/app' => 'could not connect to postgres://postgres:' . MaskHelper::FULL_MASK . '@db:5432/app',
                'redis://redis:redis@cache:6379'                                => 'redis://redis:' . MaskHelper::FULL_MASK . '@cache:6379',

                // a password containing `@`: all of it goes
                'https://svc:S3cr3tP@ss@host/api' => 'https://svc:' . MaskHelper::FULL_MASK . '@host/api',
            ] as $input => $expected
        ) {
            self::assertSame($expected, MaskHelper::maskString($input, $patterns));
        }
    }

    /**
     * The authority pattern needs a scheme: without one it took any `word:word@` it
     * could find and masked the middle of an ordinary url.
     */
    public function testTheAuthorityPatternLeavesAColonAndAnAtInAPathAlone(): void
    {
        $patterns = ['/\b[a-z][a-z0-9+.-]*:\/\/[^\/\s:@]+:([^\/\s]+)@/i'];

        foreach (
            [
                'https://cdn.example.com//assets:v2@2x.png',
                'see https://example.com/a:b@c',
                'ftp://anonymous@ftp.example.com/pub',
                'scp user@host:/path',
            ] as $untouched
        ) {
            self::assertSame($untouched, MaskHelper::maskString($untouched, $patterns));
        }
    }

    public function testAStringTooLongToLookInsideIsMaskedWhole(): void
    {
        // the watchers' caps keep a value this size out; if one arrives anyway,
        // unread must not mean unmasked
        $huge = str_repeat('a', 1000001) . ' john.doe@example.com';

        self::assertSame(MaskHelper::FULL_MASK, MaskHelper::maskString($huge, [self::EMAIL_PATTERN]));

        $masked = MaskHelper::maskArrayByKeys(['ctx' => ['note' => $huge]], [], [], [self::EMAIL_PATTERN]);

        self::assertSame(MaskHelper::FULL_MASK, $masked['ctx']['note']);
    }

    public function testMaskArrayByKeysLooksInsideXmlDocuments(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            [
                'payload' => '<order><customer_email>john.doe@example.com</customer_email>'
                    . '<api_token>sk-live-secret</api_token><amount>100</amount></order>',
            ],
            ['*token*'],
            ['*email*']
        );

        self::assertSame(
            '<order><customer_email>jo****************om</customer_email>'
            . '<api_token>' . MaskHelper::FULL_MASK . '</api_token><amount>100</amount></order>',
            $masked['payload']
        );
    }

    public function testMaskArrayByKeysMasksAnXmlSubtreeAndAttributes(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            [
                'payload' => '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
                    . '<auth><username>bob</username><password>hunter2</password></auth>'
                    . '<order id="42" api_token="sk-live-secret"/></soap:Envelope>',
            ],
            ['auth', '*token*']
        );

        // a namespace prefix does not hide the element, and a match covers the
        // subtree
        self::assertStringContainsString(
            '<auth><username>' . MaskHelper::FULL_MASK . '</username>'
            . '<password>' . MaskHelper::FULL_MASK . '</password></auth>',
            $masked['payload']
        );

        // an attribute is matched by its own name, and one that matches nothing stays
        self::assertStringContainsString('id="42"', $masked['payload']);
        self::assertStringContainsString('api_token="' . MaskHelper::FULL_MASK . '"', $masked['payload']);
    }

    public function testMaskArrayByKeysMasksInsideCdata(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            ['payload' => '<root><secret><![CDATA[very-secret]]></secret><note>keep me</note></root>'],
            ['*secret*']
        );

        self::assertStringContainsString('<![CDATA[' . MaskHelper::FULL_MASK . ']]>', $masked['payload']);
        self::assertStringContainsString('<note>keep me</note>', $masked['payload']);
    }

    public function testMaskArrayByKeysKeepsAnXmlDocumentByteForByteWhenNothingMatches(): void
    {
        // re-serialising normalises whitespace, so an untouched document must not
        // go through it at all
        $data = [
            'payload' => "<root>\n  <page>2</page>\n</root>",
        ];

        self::assertSame($data, MaskHelper::maskArrayByKeys($data, ['*token*']));
    }

    public function testMaskArrayByKeysKeepsTheXmlDeclarationOnlyWhenItWasThere(): void
    {
        $withDeclaration = MaskHelper::maskArrayByKeys(
            ['payload' => '<?xml version="1.0" encoding="UTF-8"?><root><token>abc</token></root>'],
            ['*token*']
        );

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $withDeclaration['payload']);

        $without = MaskHelper::maskArrayByKeys(
            ['payload' => '<root><token>abc</token></root>'],
            ['*token*']
        );

        // one that never had a declaration must not gain one
        self::assertStringStartsWith('<root>', $without['payload']);
    }

    public function testAByteOrderMarkDoesNotHideADocument(): void
    {
        // a BOM is legal before the declaration and is not whitespace, so ltrim
        // leaves it and the document no longer starts with `<`
        $masked = MaskHelper::maskArrayByKeys(
            ['payload' => "\xEF\xBB\xBF<r><token>abc</token></r>"],
            ['*token*']
        );

        self::assertStringContainsString('<token>' . MaskHelper::FULL_MASK . '</token>', $masked['payload']);
    }

    public function testAnUnparseableDocumentDeclaringItsOwnValuesIsMaskedWhole(): void
    {
        // a real document with a DTD starts with `<?xml`, so a check anchored at
        // byte zero never fired; what matters is the internal subset
        $masked = MaskHelper::maskArrayByKeys(
            ['payload' => '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY s "SECRET">]><r><a>&s;</a>'],
            ['*token*']
        );

        self::assertSame(MaskHelper::FULL_MASK, $masked['payload']);
    }

    public function testAnOrdinaryPageIsNotDestroyedByTheDtdRule(): void
    {
        // markup that does not parse and declares nothing of its own: masking it
        // whole would be destruction, not caution
        $page = "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"></head><body><h1>Order</h1><br></body></html>";

        $masked = MaskHelper::maskArrayByKeys(['payload' => $page], ['*token*']);

        self::assertSame($page, $masked['payload']);
    }

    public function testABareSignDoesNotSwallowOrdinaryWords(): void
    {
        $keys = (require __DIR__ . '/../../../config/slogger.php')['masking']['full_keys'];

        $masked = MaskHelper::maskArrayByKeys(
            [
                'cache' => [
                    'design:home'   => ['value' => 'a page'],
                    'assignee:42'   => ['value' => 'a name'],
                    'signature:abc' => ['value' => 'a secret'],
                ],
            ],
            $keys
        );

        // `sign` used to match all three and take the value with it
        self::assertSame('a page', $masked['cache']['design:home']['value']);
        self::assertSame('a name', $masked['cache']['assignee:42']['value']);
        self::assertSame(MaskHelper::FULL_MASK, $masked['cache']['signature:abc']['value']);
    }

    public function testMaskArrayByKeysLeavesBrokenXmlAlone(): void
    {
        $data = ['payload' => '<root><unclosed>'];

        self::assertSame($data, MaskHelper::maskArrayByKeys($data, ['*token*']));
    }

    public function testAnXmlDocumentCannotMakeTheMaskerReadAFileOrExpandEntities(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'slogger-xxe-');

        self::assertIsString($file);

        file_put_contents($file, 'CONTENTS-OF-A-LOCAL-FILE');

        try {
            // entities are never expanded, so neither a file read nor an entity bomb
            // is reachable from a document that arrived from outside
            $masked = MaskHelper::maskArrayByKeys(
                [
                    'payload' => '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file://'
                        . $file . '">]><r><token>&x;</token></r>',
                ],
                ['*token*']
            );

            self::assertStringNotContainsString('CONTENTS-OF-A-LOCAL-FILE', $masked['payload']);

            // and it did mask a document it can read, so this is not passing on
            // nothing having happened
            self::assertSame(
                '<r><token>' . MaskHelper::FULL_MASK . '</token></r>',
                MaskHelper::maskArrayByKeys(['payload' => '<r><token>abc</token></r>'], ['*token*'])['payload']
            );
        } finally {
            unlink($file);
        }
    }

    public function testAnEntityBombDoesNotExpandWhileMasking(): void
    {
        $bomb = '<?xml version="1.0"?><!DOCTYPE b ['
            . '<!ENTITY a "aaaaaaaaaa">'
            . '<!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
            . '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">'
            . '<!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">'
            . ']><r><token>&d;</token></r>';

        $started = microtime(true);

        $masked = MaskHelper::maskArrayByKeys(['payload' => $bomb], ['*token*']);

        // libxml refuses this outright, so it never parses - and an unparseable
        // document that carries declarations is masked whole
        self::assertSame(MaskHelper::FULL_MASK, $masked['payload']);

        // nothing was expanded on the way: without LIBXML_NOENT this cannot grow
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testADocumentWhoseValuesLiveInItsDtdIsMaskedWhole(): void
    {
        // this one parses, and masking around `&secret;` would leave its definition
        // in the DTD untouched
        $masked = MaskHelper::maskArrayByKeys(
            ['payload' => '<!DOCTYPE r [<!ENTITY s "SUPERSECRET">]><r><token>&s;</token></r>'],
            ['*token*']
        );

        self::assertSame(MaskHelper::FULL_MASK, $masked['payload']);
    }

    public function testMaskArrayByKeysMasksAQueryStringParameterByParameter(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            [
                'uri'          => '/api/orders',
                'query_string' => 'page=2&api_token=tok-secret&sort=asc',
            ],
            ['*token*']
        );

        $parameters = [];

        parse_str($masked['query_string'], $parameters);

        // the shape of the request survives, the secret does not
        self::assertSame('2', $parameters['page']);
        self::assertSame('asc', $parameters['sort']);
        self::assertSame(MaskHelper::FULL_MASK, $parameters['api_token']);
    }

    public function testMaskArrayByKeysKeepsTheShapeOfAQueryStringItMasks(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            ['query_string' => 'user.name=Bob&arr[]=1&arr[]=2&flag&api_token=sk-secret&page=2'],
            ['*token*']
        );

        // only the parameter that matched changed: a parse_str/http_build_query
        // round trip rewrote `user.name`, `arr[]` and the valueless `flag` as well
        self::assertSame(
            'user.name=Bob&arr[]=1&arr[]=2&flag&api_token=' . MaskHelper::FULL_MASK . '&page=2',
            $masked['query_string']
        );
    }

    public function testMaskArrayByKeysKeepsRepeatedQueryParameters(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            ['query_string' => 'id=1&token=a&id=2&token=b'],
            ['*token*']
        );

        // both of each: the round trip kept only the last value of a repeated one
        self::assertSame(
            'id=1&token=' . MaskHelper::FULL_MASK . '&id=2&token=' . MaskHelper::FULL_MASK,
            $masked['query_string']
        );
    }

    public function testMaskedKeysThatCollideDoNotSwallowEachOther(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            [
                'cache' => [
                    'john@a.com' => 1,
                    'jomn@x.com' => 2,
                ],
            ],
            [],
            [],
            [self::EMAIL_PATTERN]
        );

        // both keys mask to the same string; overwriting would drop an entry silently
        self::assertCount(2, $masked['cache']);
        self::assertSame([1, 2], array_values($masked['cache']));
    }

    public function testMaskArrayByKeysKeepsAQueryStringWhenNothingMatches(): void
    {
        $data = [
            'query_string' => 'page=2&sort=asc',
        ];

        // parse_str is lossy, so an untouched query string must come back byte for byte
        self::assertSame($data, MaskHelper::maskArrayByKeys($data, ['*token*']));
    }

    public function testMaskArrayByKeysMasksAQueryStringAtTheTopLevelToo(): void
    {
        // watchers put `query_string` at the top level, where the depth rule would
        // otherwise skip it
        $masked = MaskHelper::maskArrayByKeys(
            ['query_string' => 'api_token=tok-secret'],
            ['*token*']
        );

        // the mask stays readable: `*` is legal in a query string
        self::assertSame('api_token=' . MaskHelper::FULL_MASK, $masked['query_string']);
    }

    public function testMaskArrayByKeysMasksAValuePatternWhereverItAppears(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            [
                'context' => [
                    'notifiable' => 'Anonymous:mail,john.doe@example.com',
                    'note'       => 'nothing to see',
                ],
            ],
            [],
            [],
            [self::EMAIL_PATTERN]
        );

        // masked in place: the string stays recognisable, the address does not survive
        self::assertSame(
            'Anonymous:mail,jo****************om',
            $masked['context']['notifiable']
        );

        self::assertSame('nothing to see', $masked['context']['note']);
    }

    public function testValuePatternsApplyAtTheTopLevelToo(): void
    {
        // value patterns are not bound to a key, so the depth rule does not apply
        $masked = MaskHelper::maskArrayByKeys(
            ['notifiable' => 'john.doe@example.com'],
            [],
            [],
            [self::EMAIL_PATTERN]
        );

        self::assertSame('jo****************om', $masked['notifiable']);
    }

    public function testValuePatternsReachInsideAJsonString(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            ['payload' => '{"note":"write to john.doe@example.com"}'],
            [],
            [],
            [self::EMAIL_PATTERN]
        );

        self::assertSame(
            '{"note":"write to jo****************om"}',
            $masked['payload']
        );
    }

    public function testAKeyMatchStillWinsOverAValuePattern(): void
    {
        // the key says this is a secret; the pattern would only have masked it partially
        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['api_token' => 'john.doe@example.com']],
            ['*token*'],
            [],
            [self::EMAIL_PATTERN]
        );

        self::assertSame(MaskHelper::FULL_MASK, $masked['context']['api_token']);
    }

    public function testAnInvalidValuePatternIsIgnored(): void
    {
        // a typo would otherwise warn for every string in every trace
        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['notifiable' => 'john.doe@example.com']],
            [],
            [],
            ['/unterminated', 42, '', self::EMAIL_PATTERN]
        );

        self::assertSame('jo****************om', $masked['context']['notifiable']);
    }

    /**
     * An object walked past the masker untouched and was unfolded by `json_encode` on
     * its way out. `Log::info('x', ['user' => $user])` shipped the password hash.
     */
    public function testAnObjectIsMaskedAsWhatItSerialisesInto(): void
    {
        $dto = new class {
            public string $email     = 'john@example.com';
            public string $api_token = 'sk-live-SECRET';
            public string $password  = 'hunter2';
            public int $id           = 7;
        };

        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['dto' => $dto]],
            ['*token*', '*password*'],
            ['*email*']
        );

        self::assertSame(MaskHelper::FULL_MASK, $masked['context']['dto']['api_token']);
        self::assertSame(MaskHelper::FULL_MASK, $masked['context']['dto']['password']);
        self::assertSame('jo************om', $masked['context']['dto']['email']);

        // and what matched nothing is still there
        self::assertSame(7, $masked['context']['dto']['id']);

        // nothing of the secret survives anywhere in the payload
        self::assertStringNotContainsString(
            'sk-live-SECRET',
            json_encode($masked, JSON_THROW_ON_ERROR)
        );
    }

    public function testAnObjectWithAStringFormIsMaskedAsThatString(): void
    {
        $stringable = new class {
            public function __toString(): string
            {
                return 'contact john@example.com';
            }
        };

        $patterns = (require __DIR__ . '/../../../config/slogger.php')['masking']['value_patterns'];

        // left as an object it serialised to `{}`: neither masked nor useful
        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['note' => $stringable]],
            [],
            [],
            $patterns
        );

        self::assertSame('contact jo************om', $masked['context']['note']);

        // and under a key that matches, nothing of it survives
        $secret = MaskHelper::maskArrayByKeys(
            ['context' => ['api_token' => $stringable]],
            ['*token*']
        );

        self::assertSame(MaskHelper::FULL_MASK, $secret['context']['api_token']);
    }

    public function testAnObjectThatCannotBeUnfoldedIsLeftForTheEncoder(): void
    {
        $cyclic            = new \stdClass();
        $cyclic->self      = $cyclic;
        $cyclic->api_token = 'sk-live-CYCLE';

        $masked = MaskHelper::maskArrayByKeys(['context' => ['c' => $cyclic]], ['*token*']);

        // the cycle is dropped by the encoder, and what could be reached is masked
        self::assertSame(MaskHelper::FULL_MASK, $masked['context']['c']['api_token']);
    }

    /**
     * Masks, not substrings: a substring rule cannot be narrowed - `auth` also matched
     * `author` - and each match took the whole subtree with it.
     */
    public function testAKeyIsMatchedAsAMaskNotAsASubstring(): void
    {
        $data = ['ctx' => [
            'authorization' => 'Bearer x',
            'auth'          => 'basic',
            'author'        => 'Leo',
            'authored_by'   => 'ed',
            'api_token'     => 'sk-1',
            'tokenizer'     => 'greedy',
            'passengers'    => 4,
            'compass'       => 'NNE',
            'user_password' => 'p',
        ]];

        $masked = MaskHelper::maskArrayByKeys($data, ['auth', 'authorization', '*token*', '*password*']);

        // an exact mask matches only itself
        self::assertSame(MaskHelper::FULL_MASK, $masked['ctx']['authorization']);
        self::assertSame(MaskHelper::FULL_MASK, $masked['ctx']['auth']);
        self::assertSame('Leo', $masked['ctx']['author']);
        self::assertSame('ed', $masked['ctx']['authored_by']);

        // a wildcard matches what the caller asked it to
        self::assertSame(MaskHelper::FULL_MASK, $masked['ctx']['api_token']);
        self::assertSame(MaskHelper::FULL_MASK, $masked['ctx']['tokenizer']);
        self::assertSame(MaskHelper::FULL_MASK, $masked['ctx']['user_password']);

        // and what nobody asked for keeps its value and its type
        self::assertSame(4, $masked['ctx']['passengers']);
        self::assertSame('NNE', $masked['ctx']['compass']);
    }

    public function testTheShippedDefaultsDoNotEatOrdinaryFields(): void
    {
        $masking = (require __DIR__ . '/../../../config/slogger.php')['masking'];

        $data = ['ctx' => [
            'author'        => 'Leo',
            'authorized'    => true,
            'passengers'    => 4,
            'compass'       => 'NNE',
            'bypass_cache'  => false,
            'signed_at'     => '2026-01-02',
            'assigned_to'   => 'bob',
            'designer'      => 'Ivan',
            'crypto_rate'   => 3.5,
            'filename'      => 'a.pdf',
        ]];

        self::assertSame(
            $data,
            MaskHelper::maskArrayByKeys(
                $data,
                $masking['full_keys'],
                $masking['partial_keys'],
                $masking['value_patterns']
            )
        );
    }

    /**
     * `private` and `session` almost always name something worth hiding, so the
     * defaults take them even where they do not. A choice, not an oversight.
     */
    public function testTheShippedDefaultsTakeTheseOnPurpose(): void
    {
        $masking = (require __DIR__ . '/../../../config/slogger.php')['masking'];

        $masked = MaskHelper::maskArrayByKeys(
            ['ctx' => ['private_notes' => 'a note', 'session_count' => 12]],
            $masking['full_keys'],
            $masking['partial_keys'],
            $masking['value_patterns']
        );

        self::assertSame(MaskHelper::FULL_MASK, $masked['ctx']['private_notes']);
        self::assertSame(0, $masked['ctx']['session_count']);
    }

    /**
     * A whole key or one of its word components: `db_pass` without `passengers`.
     */
    public function testAMaskMatchesAWordInsideAKeyButNotAnyPrefix(): void
    {
        $masking = (require __DIR__ . '/../../../config/slogger.php')['masking'];

        $mask = fn(array $keys): array => MaskHelper::maskArrayByKeys(
            ['ctx' => array_fill_keys($keys, 'SECRET')],
            $masking['full_keys'],
            $masking['partial_keys'],
            $masking['value_patterns']
        )['ctx'];

        // Symfony puts the plaintext of a Basic Auth header beside the base64 one
        foreach (
            $mask([
                'authorization', 'php-auth-user', 'php-auth-pw', 'x-forwarded-authorization',
                'db_pass', 'smtp_pass', 'passcode', 'basic_auth', 'authentication',
                'oauth', 'laravel_session', 'sessionid', 'session_key',
                'signed_payload', 'signed_url', 'privatekey', 'sms_otp',
                'iban_number', 'employee_ssn', 'recovery_key', 'apiToken',
            ]) as $key => $value
        ) {
            self::assertSame(MaskHelper::FULL_MASK, $value, $key);
        }

        // and the prefixes that are not words stay whole
        foreach (
            $mask([
                'author', 'authored_by', 'authorized', 'passengers', 'compass',
                'bypass_cache', 'signed_at', 'assigned_to', 'designer', 'pinned', 'spinner',
            ]) as $key => $value
        ) {
            self::assertSame('SECRET', $value, $key);
        }
    }

    public function testTheShippedDefaultsStillCatchWhatTheyAreFor(): void
    {
        $masking = (require __DIR__ . '/../../../config/slogger.php')['masking'];

        $masked = MaskHelper::maskArrayByKeys(
            ['ctx' => [
                'authorization'  => 'Bearer sk-live',
                'api_token'      => 'sk-1',
                'access_token'   => 'at-1',
                'user_password'  => 'p',
                'set-cookie'     => 's=1',
                'x-xsrf-token'   => 't',
                'api_key'        => 'k',
                'otp_code'       => '123456',
                'card_number'    => '4111111111111111',
                'customer_email' => 'john@example.com',
            ]],
            $masking['full_keys'],
            $masking['partial_keys'],
            $masking['value_patterns']
        );

        foreach (
            ['authorization', 'api_token', 'access_token', 'user_password',
                'set-cookie', 'x-xsrf-token', 'api_key', 'otp_code', 'card_number'] as $key
        ) {
            self::assertSame(MaskHelper::FULL_MASK, $masked['ctx'][$key], $key);
        }

        self::assertSame('jo************om', $masked['ctx']['customer_email']);
    }

    public function testMaskArrayByKeysLeavesTheTopLevelAlone(): void
    {
        $data = [
            // the watcher's own structure, not traced data
            'connection_name' => 'redis',
            'token_count'     => 3,
            'job'             => [
                'data' => [
                    'customer_email' => 'a@b.test',
                ],
            ],
        ];

        $masked = MaskHelper::maskArrayByKeys($data, ['*_name*', '*token*', '*email*']);

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

        $masked = MaskHelper::maskArrayByKeys($data, ['*api_key*', '*lastname*', '*phone*']);

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

        $masked = MaskHelper::maskArrayByKeys($data, ['*email*']);

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
        self::assertSame($data, MaskHelper::maskArrayByKeys($data, ['*token*']));
    }

    public function testMaskArrayByKeysMasksListsElementWise(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['phones' => ['+70000000001', '+70000000002']]],
            ['*phone*']
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
        // an Eloquent `array` cast hands the document over as a string, and `meta`
        // says nothing about what is inside it
        $masked = MaskHelper::maskArrayByKeys(
            [
                'changes' => [
                    'meta' => '{"customer_email":"c@d.test","order_id":43}',
                ],
            ],
            ['*email*']
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
            ['*token*']
        );

        self::assertStringNotContainsString('t-1', $masked['context']['outer']);
    }

    public function testMaskArrayByKeysKeepsAJsonStringByteForByteWhenNothingMatches(): void
    {
        $json = '{ "order_id" : 43, "url": "a\/b", "ru": "\u0410" }';

        $masked = MaskHelper::maskArrayByKeys(['changes' => ['meta' => $json]], ['*email*']);

        // an untouched document is left exactly as it came in
        self::assertSame($json, $masked['changes']['meta']);
    }

    public function testMaskArrayByKeysLeavesStringsThatOnlyLookLikeJson(): void
    {
        // prose is prose: a document opens and closes, so neither of these is one
        $data = [
            'context' => [
                'note'  => '{not json at all',
                'open'  => '[still prose',
                'plain' => 'nothing to see',
            ],
        ];

        self::assertSame($data, MaskHelper::maskArrayByKeys($data, ['email', 'token']));
    }

    public function testADocumentThatCannotBeReadIsMaskedWholeRatherThanShipped(): void
    {
        // each opens and closes like a document and cannot be decoded as one, and
        // handing them back untouched shipped every key in them
        foreach (
            [
                '{"password":"a"}' . "\n" . '{"password":"b"}',
                '{"password":"hunter2",}',
                "{\"password\":\"hunter2\",\"x\":\"a\tb\"}",
            ] as $document
        ) {
            $masked = MaskHelper::maskArrayByKeys(
                ['context' => ['body' => $document]],
                ['*password*']
            );

            self::assertSame(MaskHelper::FULL_MASK, $masked['context']['body'], $document);
        }
    }

    public function testADocumentBehindAByteOrderMarkIsStillRead(): void
    {
        // a BOM is not whitespace, so an ltrim() left the sniff looking at `\xEF`
        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['body' => "\xEF\xBB\xBF" . '{"password":"hunter2","page":2}']],
            ['*password*']
        );

        self::assertStringNotContainsString('hunter2', $masked['context']['body']);
        self::assertStringContainsString('"page":2', $masked['context']['body']);
    }

    public function testADocumentThatCannotBeReEncodedIsMaskedWholeRatherThanShipped(): void
    {
        // `1e999` decodes to INF, which json_encode refuses; falling back to the
        // original handed the document back with nothing masked
        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['body' => '{"password":"hunter2","n":1e999}']],
            ['*password*']
        );

        self::assertStringNotContainsString('hunter2', $masked['context']['body']);
    }

    public function testASelfReferencingSerializedBlobDoesNotTakeTheProcessDown(): void
    {
        // a back-reference unserialises into an array that contains itself, and
        // walking one exhausts memory - a fatal error, not a Throwable
        $blob = 'a:1:{i:0;R:1;}';

        $masked = MaskHelper::maskArrayByKeys(['context' => ['blob' => $blob]], ['*token*']);

        self::assertSame($blob, $masked['context']['blob']);
    }

    public function testTheWalkGivesUpBeforeItRunsOutOfMemory(): void
    {
        $deep = [];

        for ($level = 0; $level < 200; $level++) {
            $deep = ['next' => $deep];
        }

        $masked = MaskHelper::maskArrayByKeys(['context' => $deep], ['*token*']);

        $encoded = json_encode($masked, JSON_THROW_ON_ERROR);

        // past the backstop the walk stops and masks what is left whole
        self::assertStringContainsString('["' . MaskHelper::FULL_MASK . '"]', $encoded);
        self::assertLessThan(200, substr_count($encoded, '"next"'));
    }

    public function testMaskArrayByKeysMasksAJsonStringWholeWhenItsOwnKeyMatches(): void
    {
        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['auth_payload' => '{"a":1}']],
            ['auth*']
        );

        // the key itself is flagged, so the value is masked as a value, not parsed
        self::assertNotSame('{"a":1}', $masked['context']['auth_payload']);
        self::assertNull(json_decode($masked['context']['auth_payload'], true));
    }
}
