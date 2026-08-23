<?php

namespace SLoggerLaravel\Helpers;

use Illuminate\Support\Str;

/**
 * Turns a request or response body into the array a trace carries.
 *
 * JSON becomes the structure it describes. XML cannot - that loses attributes,
 * repeated elements and namespaces - so it is carried as the document itself, under
 * one key, where the masker knows how to look inside it.
 *
 * Anything else is dropped. A body is XML only if the sender said so and it does not
 * look like a page: a framework error page carries CSRF tokens and, with a debug page
 * installed, environment values, and the masker cannot read any of it.
 */
class BodyDecoder
{
    /**
     * Prefixed like `__cleaned` and `__skipped`: the package speaking, not the
     * application.
     */
    public const XML_KEY = '__xml';

    /**
     * The masker's own limit: a body it would refuse to read must not be recorded.
     */
    public const MAX_BODY_BYTES = MaskHelper::MAX_READABLE_BYTES;

    /**
     * How much of a body is read to tell a document from a page.
     */
    private const SNIFF_BYTES = 512;

    /**
     * Anything else is not recorded as XML however well it parses: an htmx fragment
     * is well-formed markup carrying a CSRF token the masker cannot reach.
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
            // a watcher's own cap can be configured above this one
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
            // json_encode returns false on invalid UTF-8, and that replaces the
            // *whole* trace payload with an encoding error
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
     * Sniffed, not parsed: a DOM of up to a megabyte would be built in the traced
     * application's own request path, and the masker builds it again in the worker
     * anyway. The sender has already said this is XML; a page is what that gets wrong.
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
     * ltrim() leaves it and the document no longer starts with `<`.
     *
     * The UTF-8 mark only: after a UTF-16 one the document is UTF-16, where `<` is two
     * bytes and libxml needs the mark to know it.
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
