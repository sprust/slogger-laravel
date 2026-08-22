<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Masking;

use SLoggerLaravel\Helpers\BodyDecoder;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

/**
 * A body reaches the receiver, so what counts as one is a security question, not a
 * parsing convenience.
 */
class BodyDecoderTest extends BaseTestCase
{
    public function testAPageIsNotAPayload(): void
    {
        // well-formed HTML parses as XML. An error page - what a failing endpoint
        // answers with - carries CSRF tokens, inlined keys and, with a debug page
        // installed, environment values, and the masker cannot read any of it
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

    public function testSomethingThatMerelyStartsWithAngleBracketIsNotXml(): void
    {
        self::assertSame([], BodyDecoder::decode("<not xml at all\nsecret=abc", 'application/xml'));
        self::assertSame([], BodyDecoder::decode('<unclosed>', 'application/xml'));
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
        // an htmx or Turbo endpoint answers with a well-formed fragment carrying a
        // CSRF token in `value="…"` - which the masker, matching names, cannot reach.
        // Parsing says it is markup; only the sender says it is a payload
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
        // a trace's data is serialised with json_encode; invalid UTF-8 used to make
        // that fail and replace the *entire* payload of the trace - ip, uri, method,
        // headers and all - with a single encoding error
        // with the declaration a real legacy API sends, which is what makes libxml
        // accept it - without one the document simply fails to parse and this guard
        // is never reached, which is how the test used to pass for the wrong reason
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
        // the watcher's own cap is configurable above the masker's, which is how a
        // body could be recorded that the masker would then refuse to read
        $document = '<r><api_token>' . str_repeat('a', BodyDecoder::MAX_BODY_BYTES) . '</api_token></r>';

        self::assertSame(
            ['__skipped' => 'body_too_large'],
            BodyDecoder::decode($document, 'application/xml')
        );
    }
}
