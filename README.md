# SLogger Laravel

**English** | [Русский](README.ru.md)

SLogger Laravel is a tracing/observability package for Laravel apps. It records request/command/job/event/etc. traces and delivers them to a remote backend via configurable dispatchers.

This README documents installation, configuration, watchers, masking, dispatchers, and usage patterns.

## Upgrading to 2.0

Masking moved out of the traced application and into the dispatcher job.

- **Per-watcher masking is gone.** `input.headers_masking`, `input.parameters_masking`,
  `output.headers_masking`, `output.fields_masking` and the model watcher's `masks` are
  no longer read. Leftovers in a published config are ignored, not an error - which
  means any key you added there stops being masked. **Check your own keys against
  `masking.full_keys`** and port over what the shipped list does not already cover -
  it covers the shipped defaults plus `ssn`, `iban` and `card_number`, not whatever
  else you added yourself.
- **Keys are masks, not substrings.** A mask is matched against the whole key and
  against each of its word components, so `auth` covers `php-auth-pw` and `x-auth-user`
  and not `author`, and `*token*` covers `api_token` and `tokenizer`. If you ported
  keys from the old per-watcher lists, they were `Str::is` patterns there too -
  `*token*`, `*password*` - so they carry over unchanged.
- **Global lists instead**, under `masking.full_keys`, `masking.partial_keys` and
  `masking.value_patterns` - the last matching the value rather than the key. They are
  read from your config, so **republish the config** (or add the `masking` section by
  hand): a published config from before 2.0 has no section at all, and a list that is
  missing is a list that masks nothing.
- **A masked secret keeps nothing.** A value under a `masking.full_keys` key becomes
  `********` - the previous release left the first and last third of every string
  readable, which for a token or a password is not a mask. Values under
  `masking.partial_keys` (addresses, phone numbers, names) keep two characters at each
  end, which is what tells two records apart.
- **Restart the slogger workers together with the application.** Masking happens in the
  worker now, so a worker still running 1.x drains a 2.0 queue and ships those batches
  unmasked. Deploy the workers first, or drain the slogger queue across the switch.
- **The slogger queue now holds unmasked trace data.** Masking happens on the way out,
  so whatever the watchers collected sits in the queue store until the batch is sent.
  Give that queue the retention and access rules the data deserves - a separate Redis
  database or queue connection, and no long-lived `failed_jobs` rows for it.
- **Trace data changed shape** where the old shape put application data out of the
  masker's reach, or into a tag, which nothing masks:
  - cache values are nested under their cache key (`cache.<key>.value`);
  - mail addresses are nested under `message` and carried as `email`/`full_name` pairs
    instead of address-as-key;
  - an anonymous notifiable's routes moved out of the `Anonymous:...` string into
    `recipients` (and an address left in a string like that is now masked by a
    value pattern anyway);
  - a url's query string is split off into `query` and `query_string`;
  - **route parameter values are no longer tags, and no longer the `uri`.** A request
    is tagged and titled with the route pattern - `/reset/{token}` - and the values
    live in `route_parameters`, where the key list reaches them by parameter name.
  - the outbound `uri` loses its userinfo, so credentials written into a url do not
    reach a tag;
  - values added through `TraceDataComplementer::add()` land under `__add`
    rather than at the top level, which is what puts them in the masker's reach. A
    plain value added there belongs to the current unit of work and is dropped when
    it ends - the next job in a `queue:work` worker, and the next request under
    Octane, do not inherit it. A `Closure` is the other half: registered once, for
    the process, and evaluated afresh for every trace, which is what a
    `fn() => auth()->id()` in a service provider needs.
- **An XML body is now recorded**, under `__xml`, where before it was dropped:
  both watchers ran every body through `json_decode`, and an XML one gave `[]`. A
  consumer that assumed a body is always a decoded structure will now also see a
  single-key array holding the document as a string.
- **The outbound parent-trace header changed value.** It used to carry the trace
  enclosing the call; it now carries the trace of the call itself, so a service you
  call hangs its trace under that call rather than beside it. Cross-service trees
  gain a level. Nothing has to be reconfigured, but a receiver that reasons about
  depth will see the difference.

  Anything consuming those fields on the receiving side needs updating.
- **Laravel 10.26** is the new floor. `src/` needs 10.12 (`JobTimedOut` landed there),
  but the test suite runs on testbench's workbench, whose earliest release requires
  10.26 - and an untested floor is not a supported one. 10.17 was claimed for a while
  and was never true: the suite installs there and 152 of its tests error out, because
  the testbench that pairs with 10.17 does not know how to discover the workbench
  config, so the package reads itself as disabled.
- API changes if you build formatters yourself: `RequestDataFormatter` keeps its
  `requestHeaders`, `requestParameters`, `responseHeaders` and `responseFields`
  arguments and the matching `add*()` methods, but each of them now takes a plain list
  of full masks **or** a `RequestPreparer\Masks` - full keys, partial keys and value
  patterns, the same three lists the global section has.
  `MaskHelper::maskArrayByList()`/`maskArrayByPatterns()` are gone;
  `MaskHelper::maskValue()` now masks a string whole, and `maskValuePartially()` is the
  one that keeps a couple of characters.
- API changes if you extend the core: `Processor::stopDetached()` is gone - `stop()`
  closes a parent trace whichever way it was started - and so are
  `Processor::handleSeparateTracing()` and `Processor::registerWatcher()`, which the
  service provider now does itself. `DispatcherProcessorInterface` gained
  `getChildCommandName()`: the master saves that name and looks its children up by it,
  so it has to be what the process table will show.

## Requirements

- PHP >= 8.2
- `ext-pcntl` and `ext-posix` - the dispatcher supervises worker processes
- Laravel 10.26+ (tested on 10, 11 and 12)
- Queue driver for `queue` dispatcher

## Installation

1) Install the package (via Composer in your app):

```bash
composer require slogger/laravel
```

2) Publish config:

```bash
php artisan vendor:publish --tag=slogger-laravel
```

3) Configure env and `config/slogger.php` (see below).

## Quick Start

Enable and use the queue dispatcher:

```dotenv
SLOGGER_ENABLED=true
SLOGGER_TOKEN=your-api-token
SLOGGER_DISPATCHER=queue
SLOGGER_DISPATCHER_QUEUE_CONNECTION=redis
SLOGGER_DISPATCHER_QUEUE_NAME=slogger
SLOGGER_LOG_REQUESTS_ENABLED=true
```

Then start dispatcher workers:

```bash
php artisan slogger:dispatcher:start
```

## Trace Collection Backend

SLogger Laravel sends traces to a separate backend service. The reference backend project is:

```text
https://github.com/sprust/slogger
```

Use its setup instructions to provision the server and configure the API client URL/token in this package.

### Custom backend / client

You can replace the backend by providing your own API client. Redefine `ApiClientFactory::create` and return a custom implementation of `SLoggerLaravel\\Dispatcher\\ApiClients\\ApiClientInterface` that sends traces to your backend.

## Configuration

All configuration lives in `config/slogger.php` with environment overrides. Key sections:

### General

```dotenv
SLOGGER_ENABLED=false
SLOGGER_TOKEN=
SLOGGER_TRACE_ID_PREFIX=
SLOGGER_LOG_CHANNEL=daily
```

- `SLOGGER_ENABLED`: globally toggle all tracing.
- `SLOGGER_TOKEN`: API token for dispatchers.
- `SLOGGER_TRACE_ID_PREFIX`: custom prefix for trace IDs. If empty, uses slugged `app.name` or `app`.
- `SLOGGER_LOG_CHANNEL`: where internal errors are logged.

### Dispatchers

```dotenv
SLOGGER_DISPATCHER=queue
SLOGGER_DISPATCHER_QUEUE_CONNECTION=slogger-rabbitmq
SLOGGER_DISPATCHER_QUEUE_NAME=slogger
SLOGGER_DISPATCHER_QUEUE_WORKERS_COUNT=3
SLOGGER_DISPATCHER_QUEUE_API_CLIENT=socket
SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL=tcp://0.0.0.0:0002
SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TIMEOUT=10
```

- `SLOGGER_DISPATCHER`: `queue` or `memory`.
- `queue` dispatcher runs worker processes (similar to Horizon) and sends traces via HTTP or socket client.
- `memory` dispatcher stores traces in memory (useful for tests/dev).

`SLOGGER_DISPATCHER_QUEUE_CONNECTION` is **required** for the `queue` dispatcher — there is
no fallback to `QUEUE_CONNECTION` on purpose: telemetry must not silently share the
application queue connection. Use a dedicated connection.

`SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TIMEOUT` is the read/write timeout of the socket
client in seconds (default `10`). It gives headroom when the receiver is saturated and its
acknowledgement is genuinely late; the connect timeout is separate and stays at 2 seconds.

If the receiver closes the connection (restart, deploy, network fault), the client detects it
and reconnects transparently — exactly one retry per batch. Timeouts are **not** retried this
way: that would turn a saturated receiver into a reconnect storm; they are handled by the job
retry policy below.

Send retries are fixed by design: 5 attempts with backoff of 5/10/30/60 seconds between them.
After the attempts are exhausted the batch is **dropped** with a rate-limited warning in the
SLogger log channel — telemetry never fills the `failed_jobs` storage.

### Request parent trace header

```dotenv
SLOGGER_REQUESTS_HEADER_PARENT_TRACE_ID_KEY=x-parent-trace-id
```

Allows linking child traces to parent requests via a custom header. The middleware
reads it from the incoming request and sets it on the response it returns, so the
client actually receives it. (It used to be set in `terminate()`, which under FPM runs
after the response has already been sent - cross-service correlation only ever worked
in tests.)

### Watchers (enable/disable)

```dotenv
SLOGGER_LOG_COMMANDS_ENABLED=true
SLOGGER_LOG_JOBS_ENABLED=true
SLOGGER_LOG_REQUESTS_ENABLED=true
SLOGGER_LOG_CACHE_ENABLED=true
SLOGGER_LOG_DATABASE_ENABLED=true
SLOGGER_LOG_DUMP_ENABLED=true
SLOGGER_LOG_EVENT_ENABLED=true
SLOGGER_LOG_GATE_ENABLED=true
SLOGGER_LOG_HTTP_ENABLED=true
SLOGGER_LOG_LOG_ENABLED=true
SLOGGER_LOG_MAIL_ENABLED=true
SLOGGER_LOG_MODEL_ENABLED=true
SLOGGER_LOG_NOTIFICATION_ENABLED=true
SLOGGER_LOG_SCHEDULE_ENABLED=true
```

## What SLogger Writes

Each trace contains:
- `trace_id`, `parent_trace_id`, `type`, `status`, `tags`
- `data` (watcher-specific payload)
- `duration`, `memory`, `cpu`, `logged_at`

`memory` is the percentage of `memory_limit` in use, and is **null when there is no
limit** (`memory_limit = -1`, the CLI default) - there is nothing to be a percentage
of. `cpu` is the one-minute load average as a percentage of the machine's capacity,
normalised by core count, and can exceed 100 on an overloaded machine.

Watcher data highlights:
- `request`: url (without the query string), method, action, query/query_string, route_parameters, headers/params, response (for JSON and XML responses)
- `job`: connection, payload, status (`processed`, `failed`, `released_after_exception`, `timed_out`, `exception_occurred`), exception
- `event`: listeners, broadcast, optional serialized payload
- `model`: action, model class, key, changes
- `mail`: mailable/notification, queued, `message` (from/reply_to/to/cc/bcc as `email`/`full_name` pairs, subject)
- `notification`: notifiable, channel, queued, `recipients`, response
- `cache`: type, key, and `cache.<key>` (value, tags, expiration). A value too long for
  the masker to read is recorded as `__skipped` instead
- `db`: query, bindings, time. The watcher masks a binding itself, by length: a string
  longer than five characters becomes `********`, a shorter or numeric one is kept
- `http-client`: method, url, query/query_string, request/response (concurrent requests are traced independently, so `Http::pool()` works)
- `schedule`: command, description, cron, output (read up to the masker's limit)
- `dump`, `log`, `gate`: dump/message/ability info

A queue worker fails a timed out job from its `SIGALRM` handler, i.e. in the middle of
whatever the job was doing, and kills itself right after. Traces started by the job and
still open at that moment are closed as `failed` and tagged `__interrupted`, keeping the
data they had collected; a job that is retried instead of failed is closed by the
`JobTimedOut` event. A trace can still be left in the `started` status when the signal
arrives while a trace is being pushed to the dispatcher — tracing is paused there, so the
timeout events are dropped — or when the worker is killed by a signal it does not handle.

## Requests

### Middleware

For HTTP request tracing, add the middleware to the routes you want traced:

```php
\SLoggerLaravel\Middleware\HttpMiddleware::class
```

### Request watcher config

`config/slogger.php`:

```php
'watchers' => [
    [
        'class'   => \SLoggerLaravel\Watchers\Parents\RequestWatcher::class,
        'enabled' => env('SLOGGER_LOG_REQUESTS_ENABLED', false),
        'config'  => [
            // log only matched paths (optional)
            'only_paths' => [
                // 'api/*',
            ],

            // skip matched paths
            'excepted_paths' => [
                // 'health',
            ],

            'input' => [
                // apply input formatting only for these paths
                'only_paths' => [
                    // 'api/*',
                ],

                // hide all request params for these paths
                'hidden_paths' => [
                    // 'auth/*',
                ],

            ],

            'output' => [
                // apply response formatting only for these paths
                'only_paths' => [
                    // 'api/*',
                ],

                // hide all response data for these paths
                'hidden_paths' => [
                    // 'auth/*',
                ],

                // limit json response size (bytes)
                'max_content_length' => 1000000,
            ],
        ],
    ],
],
```

#### `only_paths`
- `only_paths` (top-level): log only matched request paths.
- `input.only_paths`: apply input formatting only to matched paths (others are scrubbed).
- `output.only_paths`: apply output formatting only to matched paths (others are scrubbed).

Patterns use Laravel `Str::is` matching.

### JSON response size

A body that is not JSON but **parses as XML** - a SOAP envelope, an XML API - is
carried as the document itself, under `__xml`:

```json
{"__xml": "<order><api_token>********</api_token></order>"}
```

Not as an array converted from it: that would lose attributes, repeated elements and
namespaces, and a trace that no longer matches the document it describes is worth
little. The masker looks inside such a string (see "Masking Rules"), so the document is
masked in place. This applies in both directions and to both watchers - an incoming
request body Laravel does not parse into `input()`, an outgoing one, and either
response.

**The sender has to say so.** A body is recorded as XML only when its `Content-Type`
is one (`application/xml`, `text/xml`, `application/soap+xml`, `*+xml`) *and* it parses
as XML. Parsing alone is not enough: an HTML fragment - what an htmx or Turbo endpoint
returns - is well-formed markup carrying a CSRF token in `value="…"`, and the masker
matches *names*, so it cannot reach it. A body that is neither JSON nor labelled XML is
dropped, as it always was.

A body is also dropped, with `{"__skipped": "body_too_large"}`, above the size the
masker will read (1 MB), and with `{"__skipped": "non_utf8_body"}` when it is not
valid UTF-8 - a trace's data is serialised with `json_encode`, and invalid bytes there
would replace the whole payload of that trace with an encoding error, not just the
body.

Large responses are skipped and marked with:

```json
{"__skipped": "response_too_large"}
```

## Masking Rules

The global lists run **in the dispatcher job**, right before a batch is sent, and never
in the traced application. Building a trace costs the application only what it takes to
collect and hand off the data; walking a payload key by key is paid for by the
dispatcher workers instead. Two consequences follow:

- **The queue holds unmasked trace data.** Whatever the watchers collected sits in the
  queue store until the batch is sent, so the slogger queue is as sensitive as the
  traces themselves: give it its own connection, keep its retention short, and do not
  let failed batches pile up in `failed_jobs`.
- The `memory` dispatcher never masks - it has no job. It is a development and testing
  aid and sends nothing anywhere.

A single outbound client is the one exception: the formatter it is built with can name
masks of its own, and those **are** applied in the traced application, to the headers
and the body of that call - see [Masks for one client](#masks-for-one-client). They are
extra rather than instead: the global lists still run afterwards over every trace, and
a formatter that names nothing pays nothing.

What watchers otherwise do at runtime is hide and truncate: `only_paths`,
`excepted_paths`, `hidden_paths`, `max_content_length`, per-watcher `excepted` lists.
The database watcher masks on its own too, for a different reason: query bindings are
positional, so no key list can reach them and the dispatcher job has nothing to decide
by. It masks them by length - a string of more than five characters becomes `********`,
a shorter one and a numeric binding are recorded as they were. Nothing in a binding says
whether it is a password or a page number, so a short or numeric secret - a PIN, an OTP,
a card number held as an integer - does reach the receiver. Do not keep those in cleartext columns, or
turn the watcher off.

### The key lists

```php
'masking' => [
    // the value under a matching key is replaced whole, and everything below it.
    // matched case-insensitively against the whole key and each of its components.
    'full_keys' => [
        // a word, so `auth` covers `php-auth-pw` and not `author`
        'auth',
        'authentication',
        'authorization',
        'oauth',
        'passwd',
        'pass',
        'passcode',
        'passphrase',
        'pw',
        // not bare `signed`: it would take `signed_at` and `signed_by` too
        'signed_payload',
        'signed_request',
        'signed_url',
        'private',
        'privatekey',
        'session',
        'sessionid',
        'csrf',
        'jwt',
        'bearer',
        'otp',
        'totp',
        'cvv',
        'cvc',
        'pin',
        'pincode',
        'iban',
        'ssn',
        'recovery',
        // spelled out: a bare `card` would take `card_type` with it
        'cardnumber',
        'creditcard',
        'credit_card',
        // not covered by `pass`, `passwd` or `pw`: `user_pwd` splits to `pwd`
        'pwd',

        // a wildcard matches the whole key, and the bare form with it - so a
        // word listed here is not repeated above
        '*token*',
        '*password*',
        '*secret*',
        '*api_key*',
        '*apikey*',
        '*api-key*',
        '*credential*',
        '*cookie*',
        '*signature*',
        '*session_id*',
        '*card_number*',
        '*recovery_code*',
    ],

    // two characters kept at each end, so two records still look different.
    // these identify a person rather than authenticate one - never a secret here.
    //
    // no bare `name`: it is matched as a word component, and `job.name` holds a
    // job class, `listeners[].name` a listener class, a file's `name` its filename
    'partial_keys' => [
        'username',
        'user_name',
        'nickname',
        'surname',
        'middlename',
        'middle_name',
        'fullname',
        'full_name',

        '*email*',
        '*phone*',
        '*recipient*',
        '*firstname*',
        '*first_name*',
        '*lastname*',
        '*last_name*',
    ],

    // matched against the value and masked in place, for what no key name points
    // at - an address in a log line. an invalid pattern is ignored, not fatal.
    //
    // order matters: first match wins, so narrow before broad. a capture group
    // masks the group and keeps the rest.
    'value_patterns' => [
        // postgres://app:secret@db. a scheme is required, so `//assets:v2@2x.png`
        // is left alone; the group runs to the last `@`
        'url_credentials' => '/\b[a-z][a-z0-9+.-]*:\/\/[^\/\s:@]+:([^\/\s]+)@/i',

        // a secret in a url, wherever it turns up. the parameter name is a word,
        // not a substring: unbounded, it took `?author=` and `?country_code=`
        'url_secret' => '/[?&](?:[\w.-]*[_-])?(?:token|apikey|api_key|api-key|secret|password|passwd|auth|authorization|signature|credential|session|sessionid)(?:[_-][\w.-]*)?=([^&\s"\'<>]+)/i',

        // whole parameter name only, or it takes `country_code` and `zip_code`
        'url_oauth_code' => '/[?&]code=([^&\s"\'<>]+)/i',

        'email' => '/[\w.+-]+@[\w-]+\.[\w.-]*[\w-]/u',
    ],
],
```

Both lists are **masks**, case-insensitive, with `*` as a wildcard. A mask is matched
against the whole key **and against each of its word components** — a key is split on
`_`, `-`, `.`, `:` and camelCase boundaries:

| Mask | Matches | Does not match |
| --- | --- | --- |
| `pass` | `pass`, `db_pass`, `smtp_pass`, `pass_hash` | `passengers`, `compass`, `bypass_cache` |
| `auth` | `auth`, `basic_auth`, `x-auth-user`, `php-auth-pw` | `author`, `authorized` |
| `token` | `token`, `api_token`, `apiToken` | `tokenizer` |
| `*token*` | `api_token`, `tokenizer` | `stock` |

Neither a substring search nor a whole-key match on its own would do. A substring rule
cannot be narrowed once it is too broad: `auth` also took `author`, and each match took
the whole value and, through inheritance, the subtree under it. A whole-key rule cannot
be widened without wildcards that bring the false positives back: `auth` then stopped
matching `php-auth-pw` — the plaintext password Symfony puts beside the base64 header.
Matching a component gives both, and a mask with wildcards is still available for names
that are one word.

A match on a parent key applies to its subtree, so `auth` covers `auth.method` too -
that needs the parent to be one level in, since the top level is not matched at all
(see below). A key in both lists is masked whole: the stricter list wins.

Masking is off only when all three lists (`value_patterns` included) are empty. That
switch does not reach the database watcher's bindings: they are positional, no key list
can say which one is a password, so the watcher masks them itself, by length, whatever
these lists hold.

The split is the point. A secret is worthless the moment any of it leaks, so
`masking.full_keys` replaces the value entirely: `********`, a fixed width, so the length of
the secret does not leak either. An address, a phone number or a name is mostly there
to tell two records apart, so `masking.partial_keys` keeps two characters at each end -
`john.doe@example.com` becomes `jo****************om`. **Never put a secret in
`partial_keys`**: what is left is enough to correlate records, and for a short value it
is enough to guess it.

A value that is a **string containing a document** is parsed, masked and serialised
back: applications hand whole documents over as strings - an Eloquent `array` cast puts
one straight into a model's changes, a SOAP call arrives as one - and the key carrying
such a string says nothing about what is inside it. Two formats are looked into:

- **JSON**, for strings that open and close like a document - `{`…`}` or `[`…`]`, so a
  line of prose beginning with `{` is still prose. Re-encoding normalises escaping, and
  a number too large or too precise for a PHP float loses precision. One that opens
  like a document and cannot be read as one - NDJSON, a raw control character, deeper
  than `json_decode` goes - is masked **whole**: nothing has read it, so nothing can
  vouch for it.
- **XML**, for strings that parse as XML. Element and attribute names are matched the
  way object keys are, a match covers the subtree (`<auth>` masks everything under
  it), a namespace prefix does not hide a name (`soap:Envelope` matches on
  `Envelope`), CDATA is masked in place, and comments and processing instructions get
  the value patterns. Re-serialising may normalise insignificant whitespace and
  attribute quoting; a document that had no XML declaration does not gain one.
- **PHP's own serialisation**, for strings starting with `a:<n>:{`. A session stored
  in the cache is one of those, holding the CSRF token and the password hash under
  keys the lists match. Objects are never instantiated while reading one, and a blob
  holding a serialised object or a back-reference is not taken apart at all - the first
  cannot be put back together without its class, the second unserialises into an array
  that contains itself. Both are left to the value patterns, which read them as the
  plain strings they are.

A document in which **nothing** matched is kept byte for byte.

Whatever the shape, the walk stops at a fixed depth and masks what is left whole. It is
a backstop rather than a limit anyone should meet: running out of memory is a fatal
error, and a fatal error in the dispatcher job takes the worker with it.

**A trace the masker cannot read is replaced, not dropped and not shipped.** Its `data`
becomes a single `__mask_error` key naming what went wrong. Masking is deterministic, so
letting the exception out would cost the whole batch and every one of its retries - the
traces around the broken one included.

XML entities are never expanded, so a document that arrived from outside cannot make
the dispatcher read a local file or unfold a billion-laughs bomb while it is being
masked - masking runs in a worker over payloads the application did not write. Two
consequences follow, and both fail **closed**: a document whose values live in an
internal DTD is masked whole, because masking around `&secret;` while leaving its
declaration in place reads as protection without being any; and a document carrying
declarations that does not parse at all is masked whole for the same reason.

Anything else that merely starts with `<` - a fragment of prose, a page - is left
alone, with the value patterns applied to it as ordinary text.

A value under a **`query_string`** key is masked parameter by parameter rather than as
a whole, so `page=2&api_token=secret` keeps the page and loses the token. This is where
the request watchers put a url's query string: a url is also a tag and a title, and
nothing masks those.

### Value patterns

Some things identify a person by their own shape, wherever they turn up, and no key
name points at them: an address inside `Anonymous:mail,john@example.com`, or in the
middle of a log message. `masking.value_patterns` are regular expressions matched
against the **value**, and what they match is masked in place - partially, so the rest
of the string stays readable:

```
'invoice sent to john.doe@example.com'  ->  'invoice sent to jo****************om'
```

They are not bound to a key, so unlike the key lists they apply at the top level too,
and they reach inside JSON strings and query strings, into **array keys**, and into a
trace's **tags** - which nothing else masks. A key match still wins: a `token` holding
an address loses all of it, not just the middle. An invalid pattern is dropped rather
than raising a warning for every string in every trace.

The key lists and the patterns cover different things and are meant to be used
together - `recipient` in `partial_keys` catches a phone number under
`recipients.vonage`, which no address pattern would ever match.

**The top level of a trace's `data` is never masked.** That level belongs to the
watcher, not to the application: `connection_name`, `request`, `changes`, `context`,
`bindings` and so on are a fixed structure, and the traced data starts one level in.
Matching therefore begins inside it - `context.customer_email` and
`job.data.customer_email` are masked, while `connection_name` is left readable even
though it contains `_name`. Watchers whose own top level used to hold application data
were reshaped so this rule holds for them too: a cache value sits under its cache key
(`cache.<key>.value`, so the key itself is what the list matches against), and mail
addresses sit under `message` as `email`/`full_name` pairs.

Widen a mask when the shipped list misses something of yours - `*ssn*` instead of
`ssn`, `*iban*` instead of `iban` - and narrow one when it catches a field you need.
Over-masking is the safe direction for telemetry, but it is a choice you make per mask
rather than one the package makes for you.

### What a key list cannot reach

Some fields are free text, and no key name describes what is inside them. The key lists
do not apply to these; only `value_patterns` do, and only for what has a shape worth
matching:

| Field | What it holds |
| --- | --- |
| `log.message` | whatever was logged |
| `dump.dump` | whatever was dumped - `dd($user->api_token)` is exactly this |
| `schedule.output` | the scheduled command's stdout |
| `db.sql`, and the sql fragment in a `db` trace's tags | the statement, though its values travel as bindings, which the watcher masks by length |

For those, the controls are the watcher's own: turn the watcher off, or keep secrets
out of what you log and dump. A trace's **tags** are in the same position - bare
strings with no key naming them - which is why value patterns apply to them too.

### Masked values

Masked values keep basic types, so a masked payload stays shaped like the original:
- `null` -> `null`
- `bool` -> `false`
- `int` -> `0`
- `float` -> `0.0`
- `string` -> `********`, or two characters at each end for a partial mask
- an object -> whatever `json_encode` would make of it, walked as an array like any
  other (one with `__toString()` is masked as its string instead). It only becomes
  `********` when there is nothing to walk - no public state, or nothing encodable

An empty string is left as it is: a mask there would claim something had been hidden.

**An array is walked, not replaced.** A matching key covers its subtree, so every
*leaf* under it is masked while the structure and the key names survive:
`{"token":{"a":"secret","b":2}}` becomes `{"token":{"a":"********","b":0}}`. Keys are
data too when the application chooses them - a cache key is `otp:<address>` often
enough - so `value_patterns` are applied to array keys and to tags as well, the two
places no key list can reach.

### Masks for one client

The lists above are global: every trace loses those keys. What only one outbound client
has to lose goes on the formatter that client is built with, and is applied in the
application, before the trace is handed off - the global lists still run afterwards, in
the dispatcher job.

Use them for a key that is a secret at one partner and a plain identifier everywhere
else: `account_number` at a payment gateway, `pan` on one checkout call. Putting those
in `masking.full_keys` would mask them in every trace that happens to use the same word.

A `RequestDataFormatter` takes four of them - the request headers, the request
parameters, the response headers and the response fields - and each applies only to the
urls that formatter matches:

```php
use SLoggerLaravel\RequestPreparer\Masks;
use SLoggerLaravel\RequestPreparer\RequestDataFormatter;

new RequestDataFormatter(
    urlPatterns: ['*partner.example/*'],
    requestHeaders: ['x-partner-signature'],
    requestParameters: new Masks(
        fullKeys: ['pan'],
        partialKeys: ['holder'],
        valuePatterns: ['/\d{16}/']
    ),
    responseHeaders: ['x-partner-token'],
    responseFields: ['account_number']
);
```

A plain list is a list of full masks - the common case, and the shape the pre-2.0
arguments had. A `Masks` names all three lists, and they mean exactly what they mean in
the `masking` section: whole-key matching against the key and each of its word
components, `*` as in `Str::is`, patterns matched against the value. The `add*()`
methods widen what was configured rather than replace it.

Unlike the global lists, these are matched from the **top level** of what they are
given: a header bag and a decoded body are the application's own all the way up.

The formatter goes to the handler the client is built with - see the Guzzle section
below.

## Guzzle / HTTP Client tracing

You can attach the SLogger handler to Guzzle:

```php
new \GuzzleHttp\Client([
    'base_uri' => 'https://url.com',
    'handler'  => app(\SLoggerLaravel\Guzzle\GuzzleHandlerFactory::class)->prepareHandler(
        (new \SLoggerLaravel\RequestPreparer\RequestDataFormatters())
            ->add(
                new \SLoggerLaravel\RequestPreparer\RequestDataFormatter(
                    urlPatterns: ['/api/auth/*', '*sensitive/some/*'],
                    hideAllRequestParameters: true,
                    hideAllResponseData: true
                )
            )
    ),
])
```

A formatter also carries the masks of that client - what this partner calls a secret
and nobody else does:

```php
new \GuzzleHttp\Client([
    'base_uri' => 'https://partner.example',
    'handler'  => app(\SLoggerLaravel\Guzzle\GuzzleHandlerFactory::class)->prepareHandler(
        (new \SLoggerLaravel\RequestPreparer\RequestDataFormatters())
            ->add(
                new \SLoggerLaravel\RequestPreparer\RequestDataFormatter(
                    urlPatterns: ['*partner.example/*'],
                    requestHeaders: ['x-partner-signature'],
                    requestParameters: new \SLoggerLaravel\RequestPreparer\Masks(
                        fullKeys: ['pan'],
                        partialKeys: ['holder'],
                        valuePatterns: ['/\d{16}/']
                    ),
                    responseHeaders: ['x-partner-token'],
                    responseFields: ['account_number']
                )
            )
    ),
])
```

Each of the four takes a plain list of full masks or a `Masks`, and applies only to the
urls the formatter matches - see [Masks for one client](#masks-for-one-client). The
global lists still run afterwards, in the dispatcher job, so what belongs here is what
only this client needs.

Without masks the response body is not decoded at all: a formatter that only hides
never reads what it is about to throw away.

## Dispatchers

### Queue dispatcher

Start the dispatcher (spawns queue workers):

```bash
php artisan slogger:dispatcher:start
```

- Parent traces are sent immediately.
- Child traces are batched (default batch size: 5).
- Orphan traces are sent immediately.
- On shutdown, remaining traces are flushed.

The master keeps every worker slot filled. One that keeps dying on boot is replaced
with a growing delay rather than once a second, and a slot counts as settled only once
its worker has stayed up a minute. Starting a second dispatcher takes over from the
first: it stops the running one, then starts its own fleet.

Stop the dispatcher:

```bash
php artisan slogger:dispatcher:stop
```

### Memory dispatcher

Stores traces in memory only. Intended for tests/local development.

## Storage

SLogger does not persist traces locally. The only local files are the dispatcher state
file and the lock beside it:

```text
storage/slogger/dispatcher-state-*.json
storage/slogger/dispatcher-state-*.json.lock
```

You may want to ignore the folder:

```gitignore
storage/slogger/*
```

## Testing

Run tests:

```bash
vendor/bin/phpunit
```

The testbench config uses in-memory sqlite and `memory` dispatcher.

## Troubleshooting

- Dispatcher not starting: verify `SLOGGER_ENABLED=true` and correct dispatcher name.
- No traces: ensure watchers are enabled and middleware is applied for requests.
- Queue dispatcher not sending: check queue workers and API client URL.
- Socket client errors: verify socket address and backend availability.

## License

MIT
