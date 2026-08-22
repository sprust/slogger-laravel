<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use SLoggerLaravel\Helpers\MaskHelper;
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
        // a falsy string is still a value: '0' under a `pin` key used to come out
        // untouched because the early return tested truthiness
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
        // the whole point: a token is worthless the moment any of it leaks, and the
        // width is fixed so the length of the secret does not leak either
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
        // strlen() counts bytes, so a two-byte character used to slip through unmasked
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
        // a session stored in the cache is one serialize() blob holding the CSRF
        // token and the password hash, and it looks like neither JSON nor XML
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

    public function testAValuePatternWithAGroupMasksOnlyTheGroup(): void
    {
        $pattern = '/[?&][\w.-]*(?:token|key)[\w.-]*=([^&\s]+)/i';

        $masked = MaskHelper::maskArrayByKeys(
            ['ctx' => ['message' => 'GET https://api.test/v1?api_key=sk_live_SECRET&page=2 failed']],
            [],
            [],
            [$pattern]
        );

        // the parameter name is what makes the line worth reading; the value is what
        // makes it dangerous
        self::assertSame(
            'GET https://api.test/v1?api_key=' . MaskHelper::FULL_MASK . '&page=2 failed',
            $masked['ctx']['message']
        );
    }

    /**
     * The group is spliced by offset, never by searching the match for the captured
     * text. A credential is routinely equal to the thing that names it -
     * `?password=pass` - and searching then masked the name and shipped the secret.
     */
    public function testAValuePatternMasksTheGroupEvenWhenItRepeatsTheNameAroundIt(): void
    {
        $patterns = (require __DIR__ . '/../../../config/slogger.php')['masking']['value_patterns'];

        foreach (
            [
                // a credential equal to the parameter that names it
                'GET /reset?password=pass'   => 'GET /reset?password=' . MaskHelper::FULL_MASK,
                'GET /v1?api_key=key'        => 'GET /v1?api_key=' . MaskHelper::FULL_MASK,
                'GET /cb?code=code&state=x'  => 'GET /cb?code=' . MaskHelper::FULL_MASK . '&state=x',
            ] as $input => $expected
        ) {
            self::assertSame($expected, MaskHelper::maskString($input, $patterns));
        }
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

        // a namespace prefix does not hide the element: `soap:Envelope` matches on
        // `Envelope`, and `auth` covers everything under it
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
        // re-serialising normalises whitespace and quoting, so a document nothing
        // matched in must not go through it at all
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
        // a BOM is legal before the declaration and is not whitespace, so ltrim leaves
        // it - and then the document does not start with `<` and is never masked
        $masked = MaskHelper::maskArrayByKeys(
            ['payload' => "\xEF\xBB\xBF<r><token>abc</token></r>"],
            ['*token*']
        );

        self::assertStringContainsString('<token>' . MaskHelper::FULL_MASK . '</token>', $masked['payload']);
    }

    public function testAnUnparseableDocumentDeclaringItsOwnValuesIsMaskedWhole(): void
    {
        // a real document with a DTD starts with `<?xml`, so a check anchored at byte
        // zero never fired. What matters is an internal subset - that is where an
        // unparseable document can be hiding values
        $masked = MaskHelper::maskArrayByKeys(
            ['payload' => '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY s "SECRET">]><r><a>&s;</a>'],
            ['*token*']
        );

        self::assertSame(MaskHelper::FULL_MASK, $masked['payload']);
    }

    public function testAnOrdinaryPageIsNotDestroyedByTheDtdRule(): void
    {
        // an HTML mail body, a stored template, a captured error page: markup that
        // does not parse as XML and declares nothing of its own. Masking it whole is
        // destruction, not caution
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
            // masking runs in the dispatcher worker over documents that arrived from
            // outside; entities are never expanded, so neither a file read nor a
            // billion-laughs expansion is reachable from one
            $masked = MaskHelper::maskArrayByKeys(
                [
                    'payload' => '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file://'
                        . $file . '">]><r><token>&x;</token></r>',
                ],
                ['*token*']
            );

            self::assertStringNotContainsString('CONTENTS-OF-A-LOCAL-FILE', $masked['payload']);

            // and the masker did do its job on a document it can read, so this is not
            // passing merely because nothing happened
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

        // libxml refuses this outright ("entity reference loop"), so it never parses
        // - and a document that carries declarations and does not parse is masked
        // whole rather than passed through, since nothing can look inside it
        self::assertSame(MaskHelper::FULL_MASK, $masked['payload']);

        // nothing was expanded on the way: the whole point of not setting
        // LIBXML_NOENT is that this cannot become 10 000 characters, or 10 billion
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testADocumentWhoseValuesLiveInItsDtdIsMaskedWhole(): void
    {
        // this one does parse. Masking around an entity reference would leave both
        // `&secret;` and its definition in the DTD untouched, which reads as
        // protection without being any
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

        // only the parameter that matched changed. A parse_str/http_build_query round
        // trip rewrote the rest: `user.name` became `user_name` - and then matched
        // `_name`, which the real key never would - `arr[]` became `arr%5B0%5D`, and
        // the valueless `flag` gained an `=`
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

        // both of each: the round trip used to keep only the last value of a repeated
        // parameter
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
        // watchers put `query_string` next to `uri`, in their own top-level structure,
        // and the top level is where the depth rule would otherwise skip it
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
        // value patterns are not bound to a key, so the depth rule does not hold them back
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
        // a typo in a configured pattern would otherwise warn for every string in
        // every trace, from inside the dispatcher job
        $masked = MaskHelper::maskArrayByKeys(
            ['context' => ['notifiable' => 'john.doe@example.com']],
            [],
            [],
            ['/unterminated', 42, '', self::EMAIL_PATTERN]
        );

        self::assertSame('jo****************om', $masked['context']['notifiable']);
    }

    /**
     * An object walked past the masker untouched and was then unfolded by
     * `json_encode` on its way out - an Eloquent model through `toArray()`, a DTO
     * through its public properties. `Log::info('x', ['user' => $user])` is as
     * ordinary as Laravel gets, and it shipped the token and the password hash.
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
     * Keys are matched as masks, not searched as substrings. A substring rule cannot
     * be narrowed - `auth` also matched `author`, `pass` matched `passengers` and
     * `compass` - and each match took the whole value, and its whole subtree with it.
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
     * `private` and `session` are words that almost always name something worth
     * hiding, so the defaults take them even where they do not - a `private_notes`
     * field, a `session_count`. That is a choice, not an oversight: narrow the mask
     * in your own config if you need one of them.
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
     * The masks match a whole key or one of its word components, which is what makes
     * `db_pass` and `php-auth-pw` reachable without `pass` also taking `passengers`.
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
        // an Eloquent `array` cast hands the whole document over as a string, and
        // `meta` says nothing about what is inside it
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
            ['auth*']
        );

        // the key itself is flagged, so the value is masked as a value, not parsed
        self::assertNotSame('{"a":1}', $masked['context']['auth_payload']);
        self::assertNull(json_decode($masked['context']['auth_payload'], true));
    }
}
