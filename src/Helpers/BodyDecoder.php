<?php

namespace SLoggerLaravel\Helpers;

/**
 * Turns a request or response body into the array a trace carries.
 *
 * JSON becomes the structure it describes. XML cannot: converting it to an array
 * loses attributes, repeated elements and namespaces, and a trace that no longer
 * matches the document is worth little. It is carried as the document itself,
 * under one key, where the masker knows how to look inside it.
 */
class BodyDecoder
{
    /**
     * The key an XML document is carried under. Prefixed like `__cleaned` and
     * `__skipped`: it is the package speaking, not the application.
     */
    public const XML_KEY = '__xml';

    /**
     * @return array<int|string, mixed>
     */
    public static function decode(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (self::looksLikeXml($contents)) {
            return [
                self::XML_KEY => $contents,
            ];
        }

        return [];
    }

    public static function looksLikeXml(string $contents): bool
    {
        return str_starts_with(ltrim($contents), '<');
    }
}
