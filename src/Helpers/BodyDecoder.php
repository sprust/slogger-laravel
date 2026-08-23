<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Support\Str;

/**
 * Turns a request or response body into the array a trace carries.
 *
 * JSON becomes the structure it describes. XML cannot: converting it to an array
 * loses attributes, repeated elements and namespaces, and a trace that no longer
 * matches the document is worth little. It is carried as the document itself,
 * under one key, where the masker knows how to look inside it.
 *
 * Anything else is dropped, as it always was. That last part is load-bearing: a
 * body is XML only if the sender said so and it does not look like a page. An HTML
 * page starts with `<` too - and a framework error page, which is what a failing
 * endpoint returns, carries CSRF tokens, inlined keys and, with a debug page
 * installed, environment values. The masker matches key names and cannot read any of
 * that, so recording it would be shipping it.
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
     */
    public const MAX_BODY_BYTES = MaskHelper::MAX_READABLE_BYTES;

    /**
     * How much of a body is read to tell a document from a page.
     */
    private const SNIFF_BYTES = 512;

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
     * Whether this is a document worth recording rather than a page.
     *
     * Sniffed, not parsed. Building a DOM of up to a megabyte here would happen in
     * the traced application's own request path - and the masker builds it again in
     * the worker anyway, which is where the package is allowed to spend time. The
     * sender has already said this is XML; all that is left is the one thing a
     * content type gets wrong often enough to matter.
     *
     * A page is not a payload: well-formed HTML parses as XML, and an error page -
     * which is what a failing endpoint answers with - carries CSRF tokens, inlined
     * keys and, with a debug page installed, environment values. The masker matches
     * key names and cannot read any of that, so recording it would be shipping it.
     */
    public static function isXml(string $contents): bool
    {
        $trimmed = self::trimForParsing($contents);

        if ($trimmed === '' || $trimmed[0] !== '<') {
            return false;
        }

        $head = substr($trimmed, 0, self::SNIFF_BYTES);

        return preg_match('/<!DOCTYPE\s+html\b|<html[\s>]/i', $head) !== 1;
    }

    /**
     * A byte order mark is legal before the declaration and is not whitespace, so
     * ltrim() leaves it - and then the document does not start with `<` and is
     * neither recognised nor masked.
     *
     * The UTF-8 mark only: after a UTF-16 one the document is UTF-16, where `<` is
     * two bytes and libxml needs the mark to know it. Stripping those never made a
     * document readable, it only made the branch look as though it had.
     */
    public static function trimForParsing(string $contents): string
    {
        $trimmed = ltrim($contents);

        if (str_starts_with($trimmed, "\xEF\xBB\xBF")) {
            return ltrim(substr($trimmed, 3));
        }

        return $trimmed;
    }
}
