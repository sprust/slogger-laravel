<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Masking;

use SLoggerLaravel\Helpers\BodyDecoder;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

/**
 * A body reaches the receiver, so what counts as one is a security question, not a
 * parsing convenience.
 */
class BodyDecoderTest extends BaseTestCase
{
    public function testXhtmlIsAPageEvenWhenItsContentTypeSaysXml(): void
    {
        // `application/xhtml+xml` passes the `+xml` check, so the content type does
        // not settle this one
        $page = '<html xmlns="http://www.w3.org/1999/xhtml"><body>'
            . '<input name="_token" value="CSRF-SECRET"/></body></html>';

        self::assertSame([], BodyDecoder::decode($page, 'application/xhtml+xml'));

        // and a doctype naming html, on a document rooted elsewhere
        self::assertSame(
            [],
            BodyDecoder::decode('<!DOCTYPE html><page><a>1</a></page>', 'application/xml')
        );
    }

    public function testAPageIsNotAPayload(): void
    {
        // well-formed HTML parses as XML, and an error page carries CSRF tokens and
        // environment values the masker cannot read
        foreach (
            [
                '<!DOCTYPE html><html><body><input name="_token" value="CSRF-SECRET"/></body></html>',
                '<html><head><title>x</title></head><body>y</body></html>',
                '<HTML><BODY>x</BODY></HTML>',
            ] as $page
        ) {
            self::assertSame([], BodyDecoder::decode($page, 'text/html'));
        }
    }

    public function testAnIllFormedDocumentIsCarriedAndThenMaskedWhole(): void
    {
        // the sender said this is XML and it is not. Telling them apart costs a full
        // parse, which happened in the application's own request path
        foreach (["<not xml at all\nsecret=abc", '<unclosed>secret=abc'] as $body) {
            self::assertSame(
                [BodyDecoder::XML_KEY => $body],
                BodyDecoder::decode($body, 'application/xml')
            );

            // caught in the worker instead, and caught closed
            $masked = MaskHelper::maskArrayByKeys(
                ['request' => ['parameters' => [BodyDecoder::XML_KEY => $body]]],
                ['*token*']
            );

            self::assertSame(
                MaskHelper::FULL_MASK,
                $masked['request']['parameters'][BodyDecoder::XML_KEY]
            );
        }
    }

    public function testAWellFormedDocumentUnderTheXmlKeyIsStillMaskedPerElement(): void
    {
        $document = '<order><api_token>sk-live-SECRET</api_token><amount>100</amount></order>';

        $masked = MaskHelper::maskArrayByKeys(
            ['request' => ['parameters' => [BodyDecoder::XML_KEY => $document]]],
            ['*token*']
        );

        $result = $masked['request']['parameters'][BodyDecoder::XML_KEY];

        self::assertStringContainsString('<api_token>' . MaskHelper::FULL_MASK . '</api_token>', $result);
        self::assertStringContainsString('<amount>100</amount>', $result);
    }

    public function testRealXmlIsCarriedAsItself(): void
    {
        $document = '<order><api_token>x</api_token></order>';

        self::assertSame([BodyDecoder::XML_KEY => $document], BodyDecoder::decode($document, 'application/xml'));

        $soap = '<soap:Envelope xmlns:soap="http://x"><soap:Body/></soap:Envelope>';

        self::assertSame(
            [BodyDecoder::XML_KEY => $soap],
            BodyDecoder::decode($soap, 'application/soap+xml; charset=utf-8')
        );
    }

    public function testMarkupWithoutAnXmlContentTypeIsNotRecorded(): void
    {
        // an htmx fragment carries a CSRF token in `value="…"`, which the masker
        // cannot reach: parsing says markup, only the sender says payload
        $fragment = '<div><form><input type="hidden" name="_token" value="CSRF-SECRET"/></form></div>';

        self::assertSame([], BodyDecoder::decode($fragment, 'text/html'));
        self::assertSame([], BodyDecoder::decode($fragment, null));
        self::assertSame([], BodyDecoder::decode($fragment, 'text/plain'));

        // and the same bytes, labelled as XML, are
        self::assertArrayHasKey(BodyDecoder::XML_KEY, BodyDecoder::decode($fragment, 'text/xml'));
    }

    public function testJsonStillWins(): void
    {
        self::assertSame(['a' => 1], BodyDecoder::decode('{"a":1}', 'application/json'));
    }

    public function testANonUtf8BodyIsNotCarriedAtAll(): void
    {
        // invalid UTF-8 made json_encode fail and replaced the *whole* trace payload
        // with an encoding error. The declaration is what makes libxml accept it -
        // without one the document fails to parse and this guard is never reached
        $cp1251 = '<?xml version="1.0" encoding="windows-1251"?><r><a>'
            . mb_convert_encoding('привет', 'windows-1251', 'UTF-8')
            . '</a></r>';

        self::assertSame(
            ['__skipped' => 'non_utf8_body'],
            BodyDecoder::decode($cp1251, 'application/xml')
        );
    }

    public function testABodyTooLargeForTheMaskerIsNotCarriedEither(): void
    {
        // a watcher cap configured above the masker's is how a body could arrive
        // that the masker then refuses to read
        $document = '<r><api_token>' . str_repeat('a', BodyDecoder::MAX_BODY_BYTES) . '</api_token></r>';

        self::assertSame(
            ['__skipped' => 'body_too_large'],
            BodyDecoder::decode($document, 'application/xml')
        );
    }
}
