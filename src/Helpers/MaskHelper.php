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

/**
 * @phpstan-type MaskingRules array{needles: string[], partialNeedles: string[], valuePatterns: string[]}
 */
class MaskHelper
{
    /**
     * A fully masked string. Fixed width on purpose: the length of a secret is
     * itself worth hiding.
     */
    public const FULL_MASK = '********';

    /**
     * Nothing matched.
     */
    private const MODE_NONE = 0;

    /**
     * Enough is left to tell two values apart; the value itself is not recoverable.
     */
    private const MODE_PARTIAL = 1;

    /**
     * Nothing of the value survives.
     */
    private const MODE_FULL = 2;

    /**
     * Characters kept at each end by a partial mask, and the shortest string that
     * keeps any: below it a partial mask is a full one.
     */
    private const PARTIAL_VISIBLE  = 2;
    private const PARTIAL_MIN_KEPT = 6;

    /**
     * Above this, a string is left alone rather than decoded or scanned.
     */
    private const MAX_STRING_LENGTH = 1000000;

    /**
     * Keys whose value is a URL query string. Such a value is masked parameter by
     * parameter instead of as a whole, so the shape of the request stays readable.
     *
     * @var string[]
     */
    private const QUERY_STRING_KEYS = [
        'query_string',
    ];

    /**
     * Masks every value whose key contains one of the keys, case-insensitively, and
     * every occurrence of one of the value patterns wherever it appears.
     *
     * `$keys` are masked whole, `$partialKeys` keep a couple of characters at each
     * end: a token is worthless the moment any of it leaks, while an address or a
     * phone number is mostly there to tell two records apart. A key matching both
     * lists is masked whole - the stricter list wins.
     *
     * `$valuePatterns` match the value instead of the key, and are masked partially.
     * Some things identify a person by their own shape, wherever they turn up - an
     * address in a `notifiable` string, or in the middle of a log message - and no
     * key name points at those.
     *
     * The top level is left alone: watchers put their own fixed structure there
     * (`connection_name`, `request`, `changes`, ...) and the traced data starts one
     * level in. Matching therefore begins inside that structure, so a top-level key is
     * neither masked itself nor able to drag its whole subtree in by name. Value
     * patterns are not bound to a key, so they apply at every level.
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
        $rules = [
            'needles'        => self::prepareNeedles($fullKeys),
            'partialNeedles' => self::prepareNeedles($partialKeys),
            'valuePatterns'  => self::preparePatterns($valuePatterns),
        ];

        if (!$rules['needles'] && !$rules['partialNeedles'] && !$rules['valuePatterns']) {
            return $data;
        }

        return self::maskNode(
            data: $data,
            rules: $rules,
            mode: self::MODE_NONE,
            depth: 1
        );
    }

    /**
     * Masks every occurrence of a value pattern in a standalone string.
     *
     * Tags and array keys are strings with no key naming them, so the key lists
     * cannot reach either. What identifies a person by its own shape still can be
     * found there.
     *
     * @param array<mixed> $valuePatterns
     */
    public static function maskString(string $value, array $valuePatterns): string
    {
        $patterns = self::preparePatterns($valuePatterns);

        if (!$patterns || $value === '' || strlen($value) > self::MAX_STRING_LENGTH) {
            return $value;
        }

        return self::maskByValuePatterns($value, $patterns);
    }

    /**
     * Masks a value whole. Scalars keep their type, so a masked payload stays
     * shaped like the original one.
     */
    public static function maskValue(mixed $value): mixed
    {
        return self::mask($value, self::MODE_FULL);
    }

    /**
     * Masks a value but keeps a couple of characters at each end, so two different
     * values still look different. Never use it for a secret: what is left is enough
     * to correlate records, and for a short value it is enough to guess it.
     */
    public static function maskValuePartially(mixed $value): mixed
    {
        return self::mask($value, self::MODE_PARTIAL);
    }

    /**
     * Tolerates a hand-written list: this is a public entry point, and a stray
     * non-string in a configured key list must not take a whole trace batch down.
     *
     * @param array<mixed> $keys
     *
     * @return string[]
     */
    private static function prepareNeedles(array $keys): array
    {
        return array_values(
            array_filter(
                array_map(
                    static fn(mixed $key): string => is_string($key) ? Str::lower($key) : '',
                    $keys
                )
            )
        );
    }

    /**
     * Drops anything that is not a usable regular expression. A typo in a configured
     * pattern would otherwise raise a warning for every string in every trace, from
     * inside the dispatcher job.
     *
     * @param array<mixed> $patterns
     *
     * @return string[]
     */
    private static function preparePatterns(array $patterns): array
    {
        $prepared = [];

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            if (@preg_match($pattern, '') === false) {
                continue;
            }

            $prepared[] = $pattern;
        }

        return $prepared;
    }

    /**
     * Walks the data instead of flattening it: a key that itself contains a dot would
     * not survive an Arr::dot()/Arr::set() round trip, and third-party payloads do
     * contain them.
     *
     * @param array<int|string, mixed> $data
     * @param MaskingRules             $rules
     * @param int                      $mode  the mode an ancestor key already imposed
     *
     * @return array<int|string, mixed>
     */
    private static function maskNode(
        array $data,
        array $rules,
        int $mode,
        int $depth
    ): array {
        $result = [];

        foreach ($data as $key => $value) {
            $segment = (string) $key;

            // the key itself, not the path it sits on: a match on a parent already
            // covers the subtree through `$mode`, and matching the joined path made
            // whether a field was masked depend on what happened to be above it
            $thisMode = $depth === 1
                ? self::MODE_NONE
                : max($mode, self::modeFor($segment, $rules));

            // an application-controlled key is data too: a cache key is `otp:<email>`
            // often enough, and no key names a key
            $maskedKey = is_string($key)
                ? self::maskByValuePatterns($key, $rules['valuePatterns'])
                : $key;

            if ($maskedKey !== $key && array_key_exists($maskedKey, $result)) {
                // two different keys can mask to the same string - `john@a.com` and
                // `jomn@x.com` both end in `jo******om`. Overwriting would drop an
                // entry silently, which is worse than an ugly key
                $maskedKey .= '#' . (count($result) + 1);
            }

            // an object is walked as what it will be serialised into.
            //
            // Neither shipped dispatcher can hand one over: the queue job encodes the
            // batch in its constructor, so by the time masking runs the data is plain
            // arrays, and the memory dispatcher does not mask. This is for a caller
            // that reaches the masker directly - a custom dispatcher, an application
            // masking something itself - where an object would otherwise pass through
            // untouched and be unfolded later by whatever serialises it
            if (is_object($value) && !$value instanceof Closure) {
                if ($value instanceof Stringable || method_exists($value, '__toString')) {
                    // its string form is what it means to a reader, and what the
                    // value patterns can search. Left as an object it serialised to
                    // `{}` - neither masked nor useful
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

            $result[$maskedKey] = $thisMode === self::MODE_NONE
                ? self::maskUnmatchedString($value, $segment, $rules)
                : self::mask($value, $thisMode);
        }

        return $result;
    }

    /**
     * What `json_encode` would make of an object, so the masker sees the same shape
     * the receiver will.
     *
     * Null when there is nothing to walk - an object with no public state, one that
     * cannot be encoded - in which case the caller leaves it alone and `mask()` or
     * the encoder deal with it as before.
     *
     * @return array<int|string, mixed>|null
     */
    private static function unfoldObject(object $value): ?array
    {
        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($encoded === false) {
            return null;
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /**
     * No key pointed at this value, so look at the value itself: it may carry a
     * structure of its own (a JSON document, a URL query string), and it may contain
     * something that identifies a person by its own shape.
     *
     * @param MaskingRules $rules
     */
    private static function maskUnmatchedString(mixed $value, string $key, array $rules): mixed
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_STRING_LENGTH) {
            return $value;
        }

        if (in_array(Str::lower($key), self::QUERY_STRING_KEYS, true)) {
            // its parameters went through this same path, patterns included
            return self::maskQueryString($value, $rules);
        }

        $masked = self::maskJsonString($value, $rules);

        if ($masked !== $value) {
            // it was a JSON document and something inside it matched; every string in
            // it has already been through here
            return $masked;
        }

        $masked = self::maskXmlString($value, $rules);

        if ($masked !== $value) {
            return $masked;
        }

        $masked = self::maskSerializedString($value, $rules);

        if ($masked !== $value) {
            return $masked;
        }

        return self::maskByValuePatterns($value, $rules['valuePatterns']);
    }

    /**
     * Masks every occurrence of a value pattern, in place, keeping the rest of the
     * string readable: `Anonymous:mail,john@example.com` stays recognisable as an
     * anonymous mail notifiable while the address itself does not survive.
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
     * Rebuilds the string around what the pattern found.
     *
     * By offset, never by searching the match for the captured text: a credential is
     * routinely equal to - or a substring of - the thing that names it
     * (`postgres:postgres@`, `?password=pass`), and searching found the first
     * occurrence, masking the name and shipping the secret.
     */
    private static function applyValuePattern(string $value, string $pattern): string
    {
        $matches = [];

        $found = @preg_match_all($pattern, $value, $matches, PREG_OFFSET_CAPTURE);

        if (!$found) {
            return $value;
        }

        $result = '';
        $cursor = 0;

        foreach ($matches[0] as $index => $whole) {
            $matched       = (string) $whole[0];
            $matchedOffset = (int) $whole[1];

            if ($matchedOffset < $cursor) {
                continue;
            }

            // a pattern with a capture group masks the group and keeps the rest:
            // `?api_key=SECRET` should lose the secret, not the name that gives it away
            $group = $matches[1][$index] ?? null;

            if (is_array($group) && $group[0] !== '' && $group[1] >= $matchedOffset) {
                /** @var string $maskedGroup */
                $maskedGroup = self::mask($group[0], self::MODE_FULL);

                $groupOffset = (int) $group[1];

                $replacement = substr($matched, 0, $groupOffset - $matchedOffset)
                    . $maskedGroup
                    . substr($matched, $groupOffset - $matchedOffset + strlen($group[0]));
            } else {
                /** @var string $replacement */
                $replacement = self::mask($matched, self::MODE_PARTIAL);
            }

            $result .= substr($value, $cursor, $matchedOffset - $cursor) . $replacement;

            $cursor = $matchedOffset + strlen($matched);
        }

        return $result . substr($value, $cursor);
    }

    /**
     * Applications hand whole JSON documents over as strings - an Eloquent `array` cast
     * puts one straight into a model's changes - and the key carrying such a string
     * says nothing about what is inside it.
     *
     * A document in which something matched is re-encoded rather than patched, so its
     * exact bytes are not preserved: escaping is normalised, and a number too large or
     * too precise for a PHP float loses precision. A document in which nothing matched
     * is returned untouched.
     *
     * @param MaskingRules $rules
     */
    private static function maskJsonString(string $value, array $rules): string
    {
        $trimmed = ltrim($value);

        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            return $value;
        }

        // depth 2: the document is the application's own data all the way up, unlike
        // the trace data it sits in, whose top level belongs to the watcher
        $masked = self::maskNode(
            data: $decoded,
            rules: $rules,
            mode: self::MODE_NONE,
            depth: 2
        );

        if ($masked === $decoded) {
            // nothing matched: keep the original bytes rather than a re-encoded
            // approximation of them
            return $value;
        }

        $encoded = json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? $value : $encoded;
    }

    /**
     * The same idea as maskJsonString(), for documents that arrive as XML: a SOAP
     * envelope, a payment gateway's callback, an `Accept: application/xml` response
     * body. The key carrying the string says nothing about what is inside it.
     *
     * Element names and attribute names are matched the way object keys are, and a
     * match covers the subtree - `<auth>` masks everything under it. Value patterns
     * apply to every text node and attribute value, matched or not.
     *
     * A document in which nothing matched is returned untouched, byte for byte. One
     * in which something did is re-serialised, so insignificant whitespace and
     * attribute quoting may differ from the original - the same trade the JSON path
     * makes.
     *
     * @param MaskingRules $rules
     */
    private static function maskXmlString(string $value, array $rules): string
    {
        $trimmed = BodyDecoder::trimForParsing($value);

        if ($trimmed === '' || $trimmed[0] !== '<' || !class_exists(DOMDocument::class)) {
            return $value;
        }

        $document = new DOMDocument();

        $previousErrors = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET, and no LIBXML_NOENT: entities are left unexpanded, so a
            // document that arrived from outside cannot make the dispatcher fetch a
            // URL or unfold a billion-laughs bomb while masking it
            $loaded = $document->loadXML($trimmed, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if (!$loaded || is_null($document->documentElement)) {
            // it did not parse, so nothing here can look inside it. That is fine for
            // a fragment of prose that happens to start with `<`, and not fine for a
            // document carrying a DTD: libxml refuses an entity bomb outright, and
            // returning it untouched would ship whatever its declarations hold
            // only when the document declares things of its own. An internal subset
            // (`<!DOCTYPE r [ … ]>`) or an `<!ENTITY` is where an unparseable
            // document can be hiding values; a bare `<!DOCTYPE html>` declares
            // nothing, and masking every page that carries one - an HTML mail body,
            // a stored template, a captured error page - is destruction, not caution
            return preg_match('/<!DOCTYPE[^>\[]*\[/i', $trimmed) === 1
                || stripos($trimmed, '<!ENTITY') !== false
                    ? self::FULL_MASK
                    : $value;
        }

        if (!is_null($document->doctype) && $document->doctype->entities->length > 0) {
            // an internal DTD defines the values, and an entity reference is not a
            // text node: masking would walk straight past `&secret;` and leave its
            // definition in the DTD untouched. Masking the document whole is the only
            // honest answer - a partial mask here reads as protection and is not
            return self::FULL_MASK;
        }

        $changed = false;

        self::maskXmlElement(
            element: $document->documentElement,
            rules: $rules,
            mode: self::MODE_NONE,
            changed: $changed
        );

        if (!$changed) {
            return $value;
        }

        $encoded = $document->saveXML();

        if ($encoded === false) {
            return $value;
        }

        // keep the document's own shape. saveXML() always writes a declaration, so
        // one that never had it must have it taken back off - and `<?xml-stylesheet`
        // is a processing instruction, not a declaration, which a str_starts_with on
        // `<?xml` used to mistake for one
        if (!preg_match('/^<\?xml\s/', $trimmed)) {
            $encoded = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $encoded, 1) ?? $encoded;
        }

        return rtrim($encoded, "\n");
    }

    /**
     * @param MaskingRules $rules
     */
    private static function maskXmlElement(
        DOMElement $element,
        array $rules,
        int $mode,
        bool &$changed
    ): void {
        $name = $element->localName ?: $element->nodeName;

        $thisMode = max($mode, self::modeFor($name, $rules));

        // no iterator_to_array: it materialises a wrapper object per node, and a flat
        // document with a hundred thousand small elements turns 4 MB of parsed DOM
        // into tens of MB of PHP objects - enough to kill a worker outright, which is
        // a fatal the job machinery cannot catch. Changing a node's text does not
        // change the structure, so a live list is safe to walk
        foreach ($element->attributes ?? [] as $attribute) {
            $attributeName = $attribute->localName ?: $attribute->nodeName;

            $attributeMode = max($thisMode, self::modeFor($attributeName, $rules));

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

            // a comment or a processing instruction is not matched by any key, but it
            // is still text somebody wrote: whether an address in one got masked used
            // to depend on whether an unrelated element matched elsewhere
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $masked = self::maskByValuePatterns($child->data, $rules['valuePatterns']);

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

    /**
     * @param MaskingRules $rules
     */
    private static function maskXmlText(string $text, int $mode, array $rules): string
    {
        if ($mode === self::MODE_NONE) {
            return self::maskByValuePatterns($text, $rules['valuePatterns']);
        }

        $masked = self::mask($text, $mode);

        return is_string($masked) ? $masked : $text;
    }

    /**
     * PHP's own serialisation format, which a framework writes without asking: a
     * session stored in the cache is one `serialize()` blob holding the CSRF token
     * and the password hash, and it matches neither the JSON nor the XML sniff.
     *
     * Objects are never instantiated - `allowed_classes: false` - so unserialising
     * a payload the application did not write cannot construct anything.
     *
     * @param MaskingRules $rules
     */
    private static function maskSerializedString(string $value, array $rules): string
    {
        if (!preg_match('/^a:\d+:\{/', $value)) {
            // only arrays: a serialised scalar carries no keys to match on anyway
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
            mode: self::MODE_NONE,
            depth: 2
        );

        if ($masked === $decoded) {
            return $value;
        }

        return serialize($masked);
    }

    /**
     * Masks a query string parameter by parameter: `page=2&token=secret` keeps the
     * page and loses the token. Masking it as one value would hide which parameters
     * were sent at all, which is most of what a query string is worth in a trace.
     *
     * Split by hand rather than through parse_str()/http_build_query(): that round
     * trip rewrites the string even where nothing matched. `user.name` comes back as
     * `user_name` (and then matches `_name`, which the real key never would),
     * `arr[]=1&arr[]=2` becomes `arr%5B0%5D=1&arr%5B1%5D=2`, a valueless `flag` gains
     * an `=`, and a repeated parameter loses all but its last value. A trace that
     * cannot be compared with the request it describes is worth much less.
     *
     * @param MaskingRules $rules
     */
    private static function maskQueryString(string $value, array $rules): string
    {
        $pairs = explode('&', $value);

        $changed = false;

        foreach ($pairs as $index => $pair) {
            if ($pair === '') {
                continue;
            }

            $separator = strpos($pair, '=');

            if ($separator === false) {
                // a valueless parameter: nothing to mask, and adding `=` would change
                // what the request looked like
                continue;
            }

            $rawName  = substr($pair, 0, $separator);
            $rawValue = substr($pair, $separator + 1);

            $name = urldecode($rawName);

            $mode = self::modeFor($name, $rules);

            $decoded = urldecode($rawValue);

            $masked = $mode === self::MODE_NONE
                ? self::maskByValuePatterns($decoded, $rules['valuePatterns'])
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
     * @param MaskingRules $rules
     */
    private static function modeFor(string $key, array $rules): int
    {
        $lowerKey = Str::lower($key);

        $components = self::keyComponents($key);

        if (self::matchesAny($lowerKey, $components, $rules['needles'])) {
            return self::MODE_FULL;
        }

        if (self::matchesAny($lowerKey, $components, $rules['partialNeedles'])) {
            return self::MODE_PARTIAL;
        }

        return self::MODE_NONE;
    }

    /**
     * Masks with `*` wildcards, matched against the whole key **and against each of
     * its word components** - `db_pass`, `x-auth-user` and `apiToken` are split on
     * `_`, `-`, `.`, `:` and camelCase boundaries.
     *
     * Substring search was too broad and could not be narrowed: `auth` also matched
     * `author`, `pass` matched `passengers` and `compass`, and each match took the
     * whole value and its subtree with it. Whole-key masks alone were too narrow:
     * `auth` then stopped matching `php-auth-pw` - the plaintext password Symfony
     * puts beside the base64 header - and `pass` stopped matching `db_pass`.
     *
     * Matching a component gives both: `pass` matches `db_pass` and `smtp_pass`,
     * because `pass` is a word there, and not `passengers` or `compass`, where it is
     * only a prefix. A mask that names a whole key still works as written, and one
     * with wildcards is only ever matched against the whole key.
     *
     * @param string[] $needles
     * @param string[] $components
     */
    private static function matchesAny(string $lowerKey, array $components, array $needles): bool
    {
        if ($needles === []) {
            return false;
        }

        if (Str::is($needles, $lowerKey)) {
            return true;
        }

        foreach ($components as $component) {
            if (Str::is($needles, $component)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The word components of a key, lowercased.
     *
     * @return string[]
     */
    private static function keyComponents(string $key): array
    {
        $split = preg_split('/[^\p{L}\p{N}]+|(?<=[\p{Ll}\p{N}])(?=\p{Lu})/u', $key) ?: [];

        return array_values(
            array_filter(
                array_map(static fn(string $part): string => Str::lower($part), $split),
                static fn(string $part): bool => $part !== ''
            )
        );
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

        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return self::FULL_MASK;
        }

        if ($value === '') {
            // there is nothing to hide, and a mask here would claim there was
            return $value;
        }

        if ($mode === self::MODE_FULL) {
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
