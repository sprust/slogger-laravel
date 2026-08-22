<?php

namespace SLoggerLaravel\Helpers;

use DOMDocument;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a request or response body into the array a trace carries.
 *
 * JSON becomes the structure it describes. XML cannot: converting it to an array
 * loses attributes, repeated elements and namespaces, and a trace that no longer
 * matches the document is worth little. It is carried as the document itself,
 * under one key, where the masker knows how to look inside it.
 *
 * Anything else is dropped, as it always was. That last part is load-bearing: a
 * body is only XML if it parses as XML, never merely because it starts with `<`.
 * An HTML page starts with `<` too - and a framework error page, which is what a
 * failing endpoint returns, carries CSRF tokens, inlined keys and, with a debug
 * page installed, environment values. The masker cannot read those, so recording
 * them would be shipping them.
 */
class BodyDecoder
{
    /**
     * The key an XML document is carried under. Prefixed like `__cleaned` and
     * `__skipped`: it is the package speaking, not the application.
     */
    public const XML_KEY = '__xml';

    /**
     * Above this a document is not parsed at all. It is the masker's own limit:
     * a body it would refuse to look inside must not be recorded unmasked.
     *
     * @see MaskHelper::MAX_STRING_LENGTH
     */
    public const MAX_BODY_BYTES = 1000000;

    /**
     * Content types that carry XML. Anything else is not recorded as XML, however
     * well it parses: an HTML fragment - what an htmx or Turbo endpoint answers with
     * - is well-formed markup carrying a CSRF token in `value="…"`, which the masker
     * matches names against and therefore cannot reach.
     */
    private const XML_CONTENT_TYPES = [
        'application/xml',
        'text/xml',
        'application/soap+xml',
    ];

    /**
     * @return array<int|string, mixed>
     */
    public static function decode(string $contents, ?string $contentType = null): array
    {
        if (trim($contents) === '') {
            return [];
        }

        if (strlen($contents) > self::MAX_BODY_BYTES) {
            // the masker refuses to look inside a string this long, and a body it
            // will not read must not be recorded unmasked. A watcher's own cap can be
            // configured above this one, which is how the gap used to open
            return [
                '__skipped' => 'body_too_large',
            ];
        }

        $decoded = json_decode($contents, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (!self::isXmlContentType($contentType) || !self::isXml($contents)) {
            return [];
        }

        if (!mb_check_encoding($contents, 'UTF-8')) {
            // a trace's data is serialised with json_encode, which returns false on
            // invalid UTF-8 - and that replaces the *entire* payload of the trace
            // with an encoding error, not just this body. A legacy XML API answering
            // in windows-1251 is enough to do it
            return [
                '__skipped' => 'non_utf8_body',
            ];
        }

        return [
            self::XML_KEY => $contents,
        ];
    }

    /**
     * Whether the sender said this is XML. An absent content type is not a yes: a
     * body nobody labelled is a body nobody promised anything about.
     */
    public static function isXmlContentType(?string $contentType): bool
    {
        if (is_null($contentType) || $contentType === '') {
            return false;
        }

        $type = Str::lower(trim(Str::before($contentType, ';')));

        if (in_array($type, self::XML_CONTENT_TYPES, true)) {
            return true;
        }

        // application/vnd.something+xml, image/svg+xml, ...
        return str_ends_with($type, '+xml');
    }

    /**
     * Whether this really is XML - parsed, not guessed from the first character.
     */
    public static function isXml(string $contents): bool
    {
        $trimmed = self::trimForParsing($contents);

        if ($trimmed === '' || $trimmed[0] !== '<' || !class_exists(DOMDocument::class)) {
            return false;
        }

        $document = new DOMDocument();

        $previousErrors = libxml_use_internal_errors(true);

        try {
            // the same flags the masker parses with, so the two never disagree about
            // what counts as XML
            $loaded = $document->loadXML(
                $trimmed,
                LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
            );
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if ($loaded === false || is_null($document->documentElement)) {
            return false;
        }

        // a page is not a payload. Well-formed HTML parses as XML, and an error page
        // - which is what a failing endpoint answers with - carries CSRF tokens,
        // inlined keys and, with a debug page installed, environment values. The
        // masker cannot read any of it, so recording it would be shipping it
        $rootName = $document->documentElement->localName ?: $document->documentElement->nodeName;

        $doctypeName = is_null($document->doctype) ? '' : $document->doctype->name;

        return Str::lower($rootName) !== 'html' && Str::lower($doctypeName) !== 'html';
    }

    /**
     * A byte order mark is legal before the declaration and is not whitespace, so
     * ltrim() leaves it - and then the document does not start with `<` and is
     * neither recognised nor masked.
     */
    public static function trimForParsing(string $contents): string
    {
        $trimmed = ltrim($contents);

        foreach (["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\xFE"] as $bom) {
            if (str_starts_with($trimmed, $bom)) {
                return ltrim(substr($trimmed, strlen($bom)));
            }
        }

        return $trimmed;
    }
}
