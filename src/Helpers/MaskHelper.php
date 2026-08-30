<?php

namespace SLoggerLaravel\Helpers;

use Closure;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMProcessingInstruction;
use DOMText;
use Illuminate\Support\Str;
use Stringable;
use Throwable;

class MaskHelper
{
    /**
     * Fixed width on purpose: the length of a secret is itself worth hiding.
     */
    public const FULL_MASK = '********';

    /**
     * Above this a string is left alone rather than decoded or scanned - so nothing
     * above it may be recorded in the first place. One number for the package.
     */
    public const MAX_READABLE_BYTES = 1000000;

    /**
     * A backstop: a self-referencing array exhausts memory, which is a fatal error and
     * not something the dispatcher job can catch.
     */
    private const MAX_DEPTH = 64;

    /**
     * Characters kept at each end, and the shortest string that keeps any.
     */
    private const PARTIAL_VISIBLE  = 2;
    private const PARTIAL_MIN_KEPT = 6;

    /**
     * Masked parameter by parameter, so the shape of the request stays readable.
     *
     * @var string[]
     */
    private const QUERY_STRING_KEYS = [
        'query_string',
    ];

    /**
     * `$fullKeys` mask whole, `$partialKeys` keep two characters at each end, a key in
     * both masks whole. `$valuePatterns` match the value: a log line has no key.
     *
     * The top level is the watcher's own structure and is never matched; the traced
     * data starts one level in. Value patterns apply at every level.
     *
     * @param array<int|string, mixed> $data
     * @param array<mixed>             $fullKeys
     * @param array<mixed>             $partialKeys
     * @param array<mixed>             $valuePatterns
     *
     * @return array<int|string, mixed>
     */
    public static function maskArrayByKeys(
        array $data,
        array $fullKeys,
        array $partialKeys = [],
        array $valuePatterns = []
    ): array {
        return self::maskArrayByRules(
            $data,
            new MaskingRules($fullKeys, $partialKeys, $valuePatterns)
        );
    }

    /**
     * The same with the lists already compiled - how anything masking more than one
     * trace should call it.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    public static function maskArrayByRules(array $data, MaskingRules $rules): array
    {
        if ($rules->isEmpty()) {
            return $data;
        }

        return self::maskNode(
            data: $data,
            rules: $rules,
            mode: MaskingRules::MODE_NONE,
            depth: 1
        );
    }

    /**
     * The same for an array whose own top level is the application's data - a header
     * bag, a decoded body - rather than a watcher's structure. Everything in it is
     * matched, first level included.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    public static function maskDataByRules(array $data, MaskingRules $rules): array
    {
        if ($rules->isEmpty()) {
            return $data;
        }

        return self::maskNode(
            data: $data,
            rules: $rules,
            mode: MaskingRules::MODE_NONE,
            depth: 2
        );
    }

    /**
     * For a standalone string - a tag, an array key - which no key list can reach.
     *
     * @param array<mixed> $valuePatterns
     */
    public static function maskString(string $value, array $valuePatterns): string
    {
        return self::maskStringByRules($value, new MaskingRules(valuePatterns: $valuePatterns));
    }

    /**
     * The same, with the patterns already compiled.
     */
    public static function maskStringByRules(string $value, MaskingRules $rules): string
    {
        if (!$rules->valuePatterns || $value === '') {
            return $value;
        }

        if (strlen($value) > self::MAX_READABLE_BYTES) {
            // nothing has read it, so nothing can vouch for it
            return self::FULL_MASK;
        }

        return self::maskByValuePatterns($value, $rules->valuePatterns);
    }

    /**
     * Scalars keep their type, so a masked payload stays shaped like the original.
     */
    public static function maskValue(mixed $value): mixed
    {
        return self::mask($value, MaskingRules::MODE_FULL);
    }

    /**
     * Keeps a couple of characters at each end, so two values still look different.
     * Never for a secret: what is left is enough to correlate records.
     */
    public static function maskValuePartially(mixed $value): mixed
    {
        return self::mask($value, MaskingRules::MODE_PARTIAL);
    }

    /**
     * Walked, not flattened: a key containing a dot does not survive an
     * Arr::dot()/Arr::set() round trip, and third-party payloads do contain them.
     *
     * @param array<int|string, mixed> $data
     * @param int                      $mode the mode an ancestor key already imposed
     *
     * @return array<int|string, mixed>
     */
    private static function maskNode(
        array $data,
        MaskingRules $rules,
        int $mode,
        int $depth
    ): array {
        if ($depth > self::MAX_DEPTH) {
            return [self::FULL_MASK];
        }

        $result = [];

        foreach ($data as $key => $value) {
            $segment = (string) $key;

            // the key itself, not the path: a parent match already covers the
            // subtree through `$mode`
            $thisMode = $depth === 1
                ? MaskingRules::MODE_NONE
                : max($mode, $rules->modeFor($segment));

            // a key is data too: a cache key is `otp:<email>` often enough
            $maskedKey = is_string($key)
                ? self::maskByValuePatterns($key, $rules->valuePatterns)
                : $key;

            if (array_key_exists($maskedKey, $result)) {
                // two keys can mask to the same string; an ugly key beats a dropped
                // entry. Checked whatever the key did, not only when it changed
                $maskedKey .= '#' . (count($result) + 1);
            }

            // an object is walked as what it will be serialised into - otherwise it
            // passes through untouched and is unfolded later by whatever encodes it
            if (is_object($value) && !$value instanceof Closure) {
                // instanceof, not method_exists(): PHP 8 adds Stringable implicitly,
                // and method_exists() throws on an incomplete object
                if ($value instanceof Stringable) {
                    $value = (string) $value;
                } else {
                    $unfolded = self::unfoldObject($value);

                    if (!is_null($unfolded)) {
                        $value = $unfolded;
                    }
                }
            }

            if (is_array($value)) {
                $result[$maskedKey] = $value === []
                    ? $value
                    : self::maskNode(
                        data: $value,
                        rules: $rules,
                        mode: $thisMode,
                        depth: $depth + 1
                    );

                continue;
            }

            $result[$maskedKey] = $thisMode === MaskingRules::MODE_NONE
                ? self::maskUnmatchedString($value, $segment, $rules)
                : self::mask($value, $thisMode);
        }

        return $result;
    }

    /**
     * What `json_encode` would make of it, so the masker sees what the receiver will.
     * Null when there is nothing to look at.
     */
    private static function unfoldObject(object $value): mixed
    {
        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($encoded === false) {
            return null;
        }

        $decoded = json_decode($encoded, true);

        if (is_array($decoded)) {
            return $decoded === [] ? null : $decoded;
        }

        // the scalar is what ships, so it is what has to be masked
        return $decoded;
    }

    /**
     * No key pointed at this value, so look at the value: it may carry a structure of
     * its own, or something recognisable by shape.
     */
    private static function maskUnmatchedString(mixed $value, string $key, MaskingRules $rules): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (strlen($value) > self::MAX_READABLE_BYTES) {
            // see maskString(): unread means unvouched-for, and it leaves whole
            return self::FULL_MASK;
        }

        if (in_array(Str::lower($key), self::QUERY_STRING_KEYS, true)) {
            // its parameters went through this same path, patterns included
            return self::maskQueryString($value, $rules);
        }

        $masked = self::maskJsonString($value, $rules);

        if ($masked !== $value) {
            return $masked;
        }

        if ($key === BodyDecoder::XML_KEY) {
            // recorded as a document, so one that will not parse cannot be masked -
            // and this is where that is caught, closed
            return self::maskXmlString($value, $rules, mustParse: true);
        }

        $masked = self::maskXmlString($value, $rules);

        if ($masked !== $value) {
            return $masked;
        }

        $masked = self::maskSerializedString($value, $rules);

        if ($masked !== $value) {
            return $masked;
        }

        return self::maskByValuePatterns($value, $rules->valuePatterns);
    }

    /**
     * In place, keeping the rest of the string readable.
     *
     * @param string[] $patterns
     */
    private static function maskByValuePatterns(string $value, array $patterns): string
    {
        foreach ($patterns as $pattern) {
            $value = self::applyValuePattern($value, $pattern);
        }

        return $value;
    }

    /**
     * By offset, never by searching the match for the captured text: in
     * `postgres:postgres@` searching masked the name and shipped the secret.
     */
    private static function applyValuePattern(string $value, string $pattern): string
    {
        $matches = [];

        if (!@preg_match_all($pattern, $value, $matches, PREG_OFFSET_CAPTURE)) {
            return $value;
        }

        $result = '';
        $cursor = 0;

        foreach ($matches[0] as $index => [$matched, $matchedOffset]) {
            // a capture group masks the group and keeps the rest; an unmatched
            // optional group reports offset -1
            $group = $matches[1][$index] ?? null;

            $groupFitsInside = is_array($group)
                && $group[0] !== ''
                && $group[1] >= $matchedOffset
                && $group[1] + strlen($group[0]) <= $matchedOffset + strlen($matched);

            if ($groupFitsInside) {
                $groupOffset = $group[1] - $matchedOffset;

                /** @var string $maskedGroup */
                $maskedGroup = self::mask($group[0], MaskingRules::MODE_FULL);

                $replacement = substr($matched, 0, $groupOffset)
                    . $maskedGroup
                    . substr($matched, $groupOffset + strlen($group[0]));
            } else {
                /** @var string $replacement */
                $replacement = self::mask($matched, MaskingRules::MODE_PARTIAL);
            }

            $result .= substr($value, $cursor, $matchedOffset - $cursor) . $replacement;

            $cursor = $matchedOffset + strlen($matched);
        }

        return $result . substr($value, $cursor);
    }

    /**
     * Applications hand whole JSON documents over as strings, and the key carrying one
     * says nothing about what is inside it.
     *
     * A document in which something matched is re-encoded, so escaping is normalised
     * and an oversized number loses precision. One in which nothing did is untouched.
     */
    private static function maskJsonString(string $value, MaskingRules $rules): string
    {
        // trimForParsing(), not ltrim(): a byte order mark is not whitespace, and it
        // hid the `{` from the sniff below
        $trimmed = rtrim(BodyDecoder::trimForParsing($value));

        // opens *and* closes like one, so prose starting with `{` is still prose
        $looksLikeDocument = str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')
            || str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']');

        if (!$looksLikeDocument) {
            return $value;
        }

        $decoded = json_decode($trimmed, true);

        if (!is_array($decoded)) {
            // NDJSON, a raw control character, too deep to decode: nothing has read
            // it, so nothing can vouch for it
            return self::FULL_MASK;
        }

        // depth 2: unlike trace data, a document is the application's own all the
        // way up
        $masked = self::maskNode(
            data: $decoded,
            rules: $rules,
            mode: MaskingRules::MODE_NONE,
            depth: 2
        );

        if ($masked === $decoded) {
            // keep the original bytes rather than a re-encoded approximation
            return $value;
        }

        $encoded = json_encode(
            $masked,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        // never the original: `1e999` decodes to INF, which json_encode refuses, and
        // falling back shipped the document with nothing masked
        return $encoded === false ? self::FULL_MASK : $encoded;
    }

    /**
     * The same for XML - a SOAP envelope, a gateway callback. Element and attribute
     * names are matched the way object keys are, and a match covers the subtree.
     *
     * Untouched when nothing matched; re-serialised otherwise, so whitespace and
     * attribute quoting may differ - the same trade the JSON path makes.
     */
    private static function maskXmlString(string $value, MaskingRules $rules, bool $mustParse = false): string
    {
        $trimmed = BodyDecoder::trimForParsing($value);

        if ($trimmed === '' || $trimmed[0] !== '<') {
            return $value;
        }

        if (!class_exists(DOMDocument::class)) {
            // no ext-dom: a body recorded as a document must not ship unread
            return $mustParse ? self::FULL_MASK : $value;
        }

        $document = new DOMDocument();

        $previousErrors = libxml_use_internal_errors(true);

        try {
            // no LIBXML_NOENT: entities stay unexpanded, so an outside document
            // cannot make the dispatcher fetch a URL or unfold an entity bomb
            $loaded = $document->loadXML($trimmed, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if (!$loaded || is_null($document->documentElement)) {
            if ($mustParse) {
                // nothing has read it, so nothing can vouch for it
                return self::FULL_MASK;
            }

            // unparseable prose is fine to keep; an internal subset is where values
            // hide, and a bare `<!DOCTYPE html>` declares nothing
            return preg_match('/<!DOCTYPE[^>\[]*\[/i', $trimmed) === 1
                || stripos($trimmed, '<!ENTITY') !== false
                    ? self::FULL_MASK
                    : $value;
        }

        if (!is_null($document->doctype) && $document->doctype->entities->length > 0) {
            // an entity reference is not a text node: masking walks past `&secret;`
            // and leaves its definition in the DTD
            return self::FULL_MASK;
        }

        $changed = false;

        self::maskXmlElement(
            element: $document->documentElement,
            rules: $rules,
            mode: MaskingRules::MODE_NONE,
            changed: $changed
        );

        if (!$changed) {
            return $value;
        }

        $encoded = $document->saveXML();

        if ($encoded === false) {
            return $value;
        }

        // saveXML() always writes a declaration, so one that never had it must have
        // it taken back off. `<?xml-stylesheet` is an instruction, not a declaration
        if (!preg_match('/^<\?xml\s/', $trimmed)) {
            $encoded = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $encoded, 1) ?? $encoded;
        }

        return rtrim($encoded, "\n");
    }

    private static function maskXmlElement(
        DOMElement $element,
        MaskingRules $rules,
        int $mode,
        bool &$changed
    ): void {
        $name = $element->localName ?: $element->nodeName;

        $thisMode = max($mode, $rules->modeFor($name));

        // no iterator_to_array: a wrapper per node turns 4 MB of DOM into tens, and
        // changing text does not change structure, so the live list is safe
        foreach ($element->attributes ?? [] as $attribute) {
            $attributeName = $attribute->localName ?: $attribute->nodeName;

            $attributeMode = max($thisMode, $rules->modeFor($attributeName));

            $masked = self::maskXmlText($attribute->value, $attributeMode, $rules);

            if ($masked !== $attribute->value) {
                $attribute->value = $masked;

                $changed = true;
            }
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                self::maskXmlElement(
                    element: $child,
                    rules: $rules,
                    mode: $thisMode,
                    changed: $changed
                );

                continue;
            }

            // no key matches these, but they are still text somebody wrote
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $masked = self::maskByValuePatterns($child->data, $rules->valuePatterns);

                if ($masked !== $child->data) {
                    $child->data = $masked;

                    $changed = true;
                }

                continue;
            }

            // DOMCdataSection extends DOMText, so a CDATA block is covered here too
            if (!$child instanceof DOMText) {
                continue;
            }

            if (trim($child->data) === '') {
                // the indentation between elements, not content
                continue;
            }

            $masked = self::maskXmlText($child->data, $thisMode, $rules);

            if ($masked !== $child->data) {
                $child->data = $masked;

                $changed = true;
            }
        }
    }

    private static function maskXmlText(string $text, int $mode, MaskingRules $rules): string
    {
        if ($mode === MaskingRules::MODE_NONE) {
            return self::maskByValuePatterns($text, $rules->valuePatterns);
        }

        $masked = self::mask($text, $mode);

        return is_string($masked) ? $masked : $text;
    }

    /**
     * Parameter by parameter: `page=2&token=secret` keeps the page and loses the
     * token. Masking it whole would hide which parameters were sent at all.
     *
     * Split by hand: a parse_str()/http_build_query() round trip rewrites the string
     * even where nothing matched - `user.name` comes back as `user_name`.
     */
    private static function maskQueryString(string $value, MaskingRules $rules): string
    {
        $pairs = explode('&', $value);

        $changed = false;

        foreach ($pairs as $index => $pair) {
            if ($pair === '') {
                continue;
            }

            $separator = strpos($pair, '=');

            if ($separator === false) {
                // valueless: adding `=` would change what the request looked like
                continue;
            }

            $rawName  = substr($pair, 0, $separator);
            $rawValue = substr($pair, $separator + 1);

            $name = urldecode($rawName);

            $mode = $rules->modeFor($name);

            $decoded = urldecode($rawValue);

            $masked = $mode === MaskingRules::MODE_NONE
                // the path every other leaf gets: a parameter can hold a document
                ? self::maskUnmatchedString($decoded, $name, $rules)
                : self::mask($decoded, $mode);

            if (!is_string($masked) || $masked === $decoded) {
                continue;
            }

            // `*` is legal in a query string, and a readable `token=********` beats
            // `token=%2A%2A%2A%2A%2A%2A%2A%2A`
            $pairs[$index] = $rawName . '=' . str_replace('%2A', '*', rawurlencode($masked));

            $changed = true;
        }

        return $changed ? implode('&', $pairs) : $value;
    }

    /**
     * A session in the cache is one `serialize()` blob holding the CSRF token and the
     * password hash, and it matches neither the JSON nor the XML sniff.
     *
     * `allowed_classes: false`, so reading a payload the application did not write
     * cannot construct anything.
     */
    private static function maskSerializedString(string $value, MaskingRules $rules): string
    {
        if (!preg_match('/^a:\d+:\{/', $value)) {
            // only arrays: a serialised scalar carries no keys to match on anyway
            return $value;
        }

        if (preg_match('/[;{][OCE]:\d+:"/', $value)) {
            // an object inside becomes an incomplete one, which cannot be inspected
            // without throwing nor put back together. Left to the value patterns
            return $value;
        }

        if (preg_match('/[;{][Rr]:\d+;/', $value)) {
            // a back-reference unserialises into an array that contains itself, and
            // walking one exhausts memory - see MAX_DEPTH
            return $value;
        }

        $decoded = @unserialize($value, ['allowed_classes' => false]);

        if (!is_array($decoded)) {
            return $value;
        }

        // depth 2: every key in it is the application's own
        $masked = self::maskNode(
            data: $decoded,
            rules: $rules,
            mode: MaskingRules::MODE_NONE,
            depth: 2
        );

        if ($masked === $decoded) {
            return $value;
        }

        return serialize($masked);
    }

    private static function mask(mixed $value, int $mode): mixed
    {
        if (is_null($value)) {
            return null;
        }

        if (is_bool($value)) {
            return false;
        }

        if (is_int($value)) {
            return 0;
        }

        if (is_float($value)) {
            return 0.0;
        }

        // instanceof, not method_exists(): see maskNode()
        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return self::FULL_MASK;
        }

        if ($value === '') {
            // a mask here would claim something had been hidden
            return $value;
        }

        if ($mode === MaskingRules::MODE_FULL) {
            return self::FULL_MASK;
        }

        $length = Str::length($value);

        if ($length < self::PARTIAL_MIN_KEPT) {
            // too short to give anything away safely
            return str_repeat('*', $length);
        }

        return Str::mask(
            string: $value,
            character: '*',
            index: self::PARTIAL_VISIBLE,
            length: $length - (self::PARTIAL_VISIBLE * 2)
        );
    }
}
