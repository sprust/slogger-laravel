# SLogger Laravel

**English** | [Русский](README.ru.md)

SLogger Laravel is a tracing/observability package for Laravel apps. It records request/command/job/event/etc. traces and delivers them to a remote backend via configurable dispatchers.

This README documents installation, configuration, watchers, masking, dispatchers, and usage patterns.

## Requirements

- PHP >= 8.2
- Laravel 10.26+ (tested on 10, 11 and 12)
- Queue driver for the `queue` dispatcher, and a queue worker running on it

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
SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL=tcp://your-slogger-host:port
SLOGGER_LOG_REQUESTS_ENABLED=true
```

Add the middleware to the routes you want traced (see [Middleware](#middleware)), then
run a queue worker on that connection and queue:

```bash
php artisan queue:work redis --queue=slogger
```

## Trace Collection Backend

SLogger Laravel sends traces to a separate backend service. The reference backend project is:

```text
https://github.com/sprust/slogger
```

Use its setup instructions to provision the server and configure the API client URL/token in this package.

### Custom backend / client

You can replace the backend with your own API client: bind your implementation of
`SLoggerLaravel\Dispatcher\ApiClients\ApiClientInterface` (one method,
`sendTraces(TracesObject $traces): void`) in a service provider of your application. Or
extend `ApiClientFactory` - a `readonly` class, so the subclass has to be `readonly`
too - and bind the subclass in its place. The client is used only by the `queue`
dispatcher's `SendTracesJob`; out of the box `SLOGGER_DISPATCHER_QUEUE_API_CLIENT`
accepts only `socket`.

## Configuration

All configuration lives in `config/slogger.php` with environment overrides. Key sections:

### General

```dotenv
SLOGGER_ENABLED=false
SLOGGER_TOKEN=
SLOGGER_TRACE_ID_PREFIX=
SLOGGER_LOG_CHANNEL=daily
SLOGGER_CONTEXT=array
```

- `SLOGGER_ENABLED`: globally toggle all tracing.
- `SLOGGER_TOKEN`: API token for dispatchers.
- `SLOGGER_TRACE_ID_PREFIX`: custom prefix for trace IDs. If empty, uses slugged `app.name` or `app`.
- `SLOGGER_LOG_CHANNEL`: where internal errors are logged.
- `SLOGGER_CONTEXT`: where the state of one unit of work is kept - `array` unless you run
  several requests or jobs at once in one process. See [Concurrency](#concurrency).

### Dispatchers

```dotenv
SLOGGER_DISPATCHER=queue
SLOGGER_DISPATCHER_QUEUE_CONNECTION=slogger-rabbitmq
SLOGGER_DISPATCHER_QUEUE_NAME=slogger
SLOGGER_DISPATCHER_QUEUE_API_CLIENT=socket
SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE=direct
SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL=tcp://0.0.0.0:0002
SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TIMEOUT=10
SLOGGER_LOCAL_PROXY_TRACES_PATH=storage/slogger/traces
SLOGGER_LOCAL_PROXY_RETENTION_HOURS=24
```

- `SLOGGER_DISPATCHER`: `queue` or `memory`.
- `queue` dispatcher puts traces on a queue; your queue workers send them via the socket client.
- `memory` dispatcher stores traces in memory (useful for tests/dev).
- `SLOGGER_DISPATCHER_QUEUE_API_CLIENT`: the client the job sends with - `socket`, the
  only one shipped.
- `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE`: `direct` (the default) sends straight to the
  receiver; `proxy` uses the local Unix socket. See [Local proxy](#local-proxy).
- `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL`: the receiver address for direct mode and the Go
  proxy's upstream; there is no default.

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
SLogger log channel — a send that fails inside the job never reaches `failed_jobs`. Only a
failure on the worker's side - a worker timeout, a worker killed mid-attempt - can still be
recorded there by Laravel.

A failed attempt never reaches the application's exception handler: the job releases itself
with the pause instead of throwing - the worker reports whatever escapes `handle()`, attempt by
attempt, and a receiver restart used to become thousands of errors in the host's own
monitoring. The exception is a `sync` connection, which has no later attempt, so the error is
thrown there as before.

### Request parent trace header

```dotenv
SLOGGER_REQUESTS_HEADER_PARENT_TRACE_ID_KEY=x-parent-trace-id
```

Links traces across services through one header:

- **incoming request**: its value becomes the parent of the request trace;
- **response**: the middleware sets this request's own trace id on the response it
  returns, so the client actually receives it (it used to be set in `terminate()`,
  which under FPM runs after the response has already been sent);
- **outbound HTTP-client call**: carries the trace id of the call itself, so the called
  service hangs its trace under that call.

An empty value turns all three off.

### Watchers (enable/disable)

Every watcher is off by default. The parent ones - requests, jobs, commands - open a
trace; the others record only inside an open one (an event listed in the event watcher's
`can_be_orphan` is the exception).

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

### Other settings

Set in `config/slogger.php` only:

- `watchers.*.config.excepted` (command and job watchers): commands and job classes not
  traced. Commands default to `queue:work`, `queue:listen`, `horizon:work` and
  `schedule:run`; jobs to `SendTracesJob`, so sending a batch is not a trace of its own.
- Event watcher: `only_events` (record only these; empty means all), `ignore_events`
  (on top of the built-in `Illuminate\*`, `Laravel\*`, `eloquent*`, `SLoggerLaravel\*`
  and a few more, which apply while `only_events` is empty), `serialize_events` (event
  classes whose JSON goes into the trace) and `can_be_orphan` (recorded even with no
  trace open). Names are `Str::is` masks, except `serialize_events`, which takes exact
  class names.
- `data_completer.excluded_file_masks`: files skipped when the call site under `__trace`
  is looked for (`Str::is` masks), for your own wrappers around the traced calls.
- `listeners`: event listeners the package registers; by default `WatcherErrorEvent`
  goes to `report()`, so a watcher failure reaches your error handler instead of the
  request.

## What SLogger Writes

Each trace contains:
- `trace_id`, `parent_trace_id`, `type`, `status`, `tags`
- `data` (watcher-specific payload, plus `__trace` - the call site - and `__add`, the
  values added through `TraceDataComplementer::add()`)
- `duration`, `memory`, `cpu`, `logged_at`
- when it is created: whether it is a parent trace - one that will get an update when
  it closes - and the id of the process that created it

`memory` is the percentage of `memory_limit` in use, and is **null when there is no
limit** (`memory_limit = -1`, the CLI default) - there is nothing to be a percentage
of. `cpu` is the one-minute load average as a percentage of the machine's capacity,
normalised by core count, and can exceed 100 on an overloaded machine; it is null
where the core count cannot be read (no `/proc/cpuinfo`, i.e. outside Linux).

Watcher data highlights:
- `request`: `uri` (the route pattern, `/reset/{token}`; an unrouted path is cut to its
  first segment), ip_address, method, action, middlewares, query/query_string,
  route_parameters, boot_time, `request.headers`/`request.parameters`,
  `response.status`/`headers`/`data` (the body when the request accepts JSON or the
  response is XML, otherwise the redirect target or view)
- `command`: command, arguments, options, exit_code
- `job`: connection_name, `job` (name, class, max_tries, timeout, backoff, data, ...), status (`processed`, `failed`, `released_after_exception`, `timed_out`, `exception_occurred`), exception.
  Only jobs dispatched while the package is enabled are traced
- `event`: listeners, broadcast, optional serialized payload
- `model`: action, model class, key, changes
- `mail`: mailable/notification, queued, `message` (from/reply_to/to/cc/bcc as `email`/`full_name` pairs, subject)
- `notification`: notification, notifiable, channel, queued, `delivery.recipients`, response
- `cache`: type (`hit`, `missed`, `set`, `forget`), key, and `cache.<key>` (value, tags,
  expiration). A value too long for the masker to read is recorded as
  `{"__skipped": "value_too_large"}` instead
- `database`: connection, sql (up to 10,000 characters), bindings; the query time is the
  trace's `duration`. The watcher masks a binding itself, by length: a string longer than
  five characters becomes `********`, a shorter one, an int or a float is kept
- `http-client`: method, uri, query/query_string, `request.headers`/`payload`,
  `response.status_code`/`headers`/`body`, exception, connection timings under `transfer` (concurrent
  requests are traced independently, so `Http::pool()` works). Only clients built with
  the handler from [Guzzle / HTTP Client tracing](#guzzle--http-client-tracing) are traced
- `schedule`: command, description, expression, timezone, user, output (read up to the
  masker's limit)
- `dump`: dump; `log`: level, message, context; `gate`: ability, result, user_id, arguments

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
                    '*',
                ],

                // above this the parameters are not recorded at all (bytes)
                'max_content_length' => 1000000,
            ],

            'output' => [
                // apply response formatting only for these paths
                'only_paths' => [
                    // 'api/*',
                ],

                // hide all response data for these paths
                'hidden_paths' => [
                    '*',
                ],

                // the same for the response body (bytes)
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

The shipped `hidden_paths` are `['*']`: request parameters and response data are recorded
as `{"__cleaned": null}` everywhere until you narrow the list to the paths that have to
stay hidden.

### Request and response bodies

A body that is not JSON but **is an XML document** - a SOAP envelope, an XML API - is
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
is one (`application/xml`, `text/xml`, `application/soap+xml`, `*+xml`) *and* it looks
like a document: it starts with `<` and its first 512 bytes are not an HTML page. Nothing
is parsed at this point. The markup alone is not enough: an HTML fragment - what an htmx or Turbo endpoint
returns - is well-formed markup carrying a CSRF token in `value="…"`, and the masker
matches *names*, so it cannot reach it. A body that is neither JSON nor labelled XML is
dropped, as it always was.

An XML body that is not valid UTF-8 is recorded as `{"__skipped": "non_utf8_body"}` - a
trace's data is serialised with `json_encode`, and invalid bytes there would replace the
whole payload of that trace with an encoding error, not just the body.

Bodies above `max_content_length` (default 1,000,000 bytes) are not recorded:

| Watcher | Marker |
| --- | --- |
| request: parameters | `{"__skipped": "request_too_large"}` |
| request: response | `{"__skipped": "response_too_large"}` |
| http-client | `{"__cleaned": "--cleaned:big-size-<bytes>--"}` |
| any, above the size the masker reads (1 MB) | `{"__skipped": "body_too_large"}` |

The last one shows up only when a watcher's own limit is set above 1 MB. The http-client
watcher also records `{"__skipped": "non_seekable_body"}` for a stream it cannot rewind.

## Masking Rules

Masking runs **in the dispatcher job**, right before a batch is sent, and never in the
traced application. Building a trace costs the application only what it takes to
collect and hand off the data; walking a payload key by key is paid for by the
queue workers instead. Two consequences follow:

- **The queue holds unmasked trace data.** Whatever the watchers collected sits in the
  queue store until the batch is sent, so the slogger queue is as sensitive as the
  traces themselves: give it its own connection, keep its retention short, and do not
  let failed batches pile up in `failed_jobs`.
- The `memory` dispatcher never masks - it has no job. It is a development and testing
  aid and sends nothing anywhere.

Watchers do not mask. What they do at runtime is hide and truncate: `only_paths`,
`excepted_paths`, `hidden_paths`, `max_content_length`, per-watcher `excepted` lists.
The one exception is the database watcher: query bindings are positional, so no key list
can reach them and the dispatcher job has nothing to decide by. It masks them itself, by
length - a string of more than five characters becomes `********`, a shorter one, an
int and a float are recorded as they were. Nothing in a binding says whether it is a
password or a page number, so a short or numeric secret - a PIN, an OTP, a card number
held as an integer - does reach the receiver. Do not keep those in cleartext columns, or
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
    // order matters: each pattern runs on what the previous ones left, so narrow
    // before broad. a capture group masks the group and keeps the rest.
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
every character that is not a letter or a digit (`_`, `-`, `.`, `:`, `/`, a space, ...)
and at camelCase boundaries:

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
`john.doe@example.com` becomes `jo****************om`; a value shorter than six
characters becomes as many `*` as it has. **Never put a secret in
`partial_keys`**: what is left is enough to correlate records, and for a short value it
is enough to guess it.

A value that is a **string containing a document** is parsed, masked and serialised
back: applications hand whole documents over as strings - an Eloquent `array` cast puts
one straight into a model's changes, a SOAP call arrives as one - and the key carrying
such a string says nothing about what is inside it. Three formats are looked into:

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
the request watchers put a url's query string: a url is also a tag and a title, which no
key list reaches - only the value patterns, and the title not even those.

### Value patterns

Some things identify a person by their own shape, wherever they turn up, and no key
name points at them: an address inside `Anonymous:mail,john@example.com`, or in the
middle of a log message. `masking.value_patterns` are regular expressions matched
against the **value**, and what they match is masked in place, so the rest of the
string stays readable: a capture group is replaced by `********`, a pattern without one
is masked partially:

```
'invoice sent to john.doe@example.com'  ->  'invoice sent to jo****************om'
```

They are not bound to a key, so unlike the key lists they apply at the top level too,
and they reach inside JSON strings and query strings, into **array keys**, and into a
trace's **tags** - which nothing else masks. A key match still wins: a `token` holding
an address loses all of it, not just the middle. An invalid pattern is dropped rather
than raising a warning for every string in every trace.

The key lists and the patterns cover different things and are meant to be used
together - `*phone*` in `partial_keys` catches a phone number under `customer.phone`,
which no address pattern would ever match.

**The top level of a trace's `data` is never masked.** That level belongs to the
watcher, not to the application: `connection_name`, `request`, `changes`, `context`,
`bindings` and so on are a fixed structure, and the traced data starts one level in.
Matching therefore begins inside it - `context.customer_email` and
`job.data.customer_email` are masked. Watchers whose own top level used to hold
application data were reshaped so this rule holds for them too: a cache value sits under
its cache key (`cache.<key>.value`, so the key itself is what the list matches against),
mail addresses sit under `message` as `email`/`full_name` pairs, and a notification's
routes under `delivery.recipients`, where `*recipient*` reaches a phone number no value
pattern would.

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
- `string` -> `********`, or two characters at each end for a partial mask (all `*`
  below six characters)
- a string longer than the masker reads (1 MB) -> `********`, whatever the key
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

## Guzzle / HTTP Client tracing

You can attach the SLogger handler to Guzzle:

```php
new \GuzzleHttp\Client([
    'base_uri' => 'https://url.com',
    'handler'  => app(\SLoggerLaravel\Guzzle\GuzzleHandlerFactory::class)->prepareHandler(
        (new \SLoggerLaravel\RequestPreparer\RequestDataFormatters())
            ->add(
                new \SLoggerLaravel\RequestPreparer\RequestDataFormatter(
                    urlPatterns: ['*/api/auth/*', '*sensitive/some/*'],
                    hideAllRequestParameters: true,
                    hideAllResponseData: true
                )
            )
    ),
])
```

The patterns are matched against the **full url** - scheme, host and query string
included - so a path pattern needs a leading `*`. `prepareHandler()` takes an existing
`HandlerStack` as its second argument, and returns the stack untouched while the
http-client watcher is off.

Formatters only hide; the watcher replaces a body of 1 MB or more, and sensitive values
are masked later, by the dispatcher job.

### Connection timings

When the call goes through curl (Guzzle's default handler), the trace carries what curl
measured, under `transfer` - for a failed call too, so a server that accepted the
connection and never answered is told apart from one that could not be reached:

```json
"transfer": {
    "namelookup_time": 0.001234,
    "connect_time": 0.021,
    "appconnect_time": 0.063,
    "pretransfer_time": 0.0631,
    "starttransfer_time": 0.25,
    "total_time": 0.3,
    "primary_ip": "93.184.216.34"
}
```

The times are in seconds and **cumulative from the start of the call**, as curl reports
them: each includes every phase before it.

| Field | What it is |
|---|---|
| `namelookup_time` | DNS lookup done |
| `connect_time` | TCP connection to the server established - the connect time, DNS included |
| `appconnect_time` | TLS handshake done (`0` over plain http) |
| `pretransfer_time` | everything ready, the request started going out |
| `starttransfer_time` | first byte of the response arrived (TTFB) |
| `total_time` | the whole call done |
| `primary_ip` | the IP address actually connected to |

A single phase is the difference of neighbouring fields: over https, TLS took
`appconnect_time - connect_time`, the server spent `starttransfer_time - pretransfer_time`.

- **A reused connection reads `0`.** A keep-alive connection taken from curl's pool was
  not looked up or connected again, so `namelookup_time` and `connect_time` are `0` -
  that is not a measurement error.
- **No curl, no `transfer`.** The stream handler and a mock report no handler stats, and
  the key is left out rather than filled with zeros.

The timings arrive through Guzzle's `on_stats` option. An `on_stats` of your own - or
the one Laravel's `Http` client sets to fill `$response->transferStats` - is still
called.

## Concurrency

A trace has state while it runs: which trace is the current parent, which parent traces
are still open, whether the watchers are paused, what `add()` said about this request.
Where a process handles one request, one job or one command at a time, that state is the
process's, and keeping it in fields on a few singletons is correct.

Under a runtime that handles several at once in one process - coroutines, an event loop,
fibers - a field is shared by all of them. A second request reads the first one's parent
id and files itself underneath it; a request inside a paused section silences the
watchers of every other request in flight; one request's `user_id` is written onto
another's traces.

So the state lives in a store, and the store decides what "current" means.

### Choosing a store

```dotenv
# array (default) | fiber | App\Tracing\YourStore
SLOGGER_CONTEXT=array
```

- **`array`** is a single map for the whole process - what every version before this one
  did, and the right answer for php-fpm, `artisan` and `queue:work`. It is the default,
  so upgrading changes nothing.
- **`fiber`** keeps a map per running `Fiber`, and a single map when none is running.
  Set it when your runtime gives each request or each job its own **PHP fiber**.
- **anything else** is taken for the class name of a store you brought yourself: a
  class implementing `TraceContextInterface`, resolved from the container. Anything else
  fails with `Unknown trace context`. The setting is not read while `SLOGGER_ENABLED` is
  off.

**Check that `fiber` is the right answer before setting it.** It tells units apart by
`Fiber::getCurrent()`, so under a runtime whose coroutines are not PHP fibers - Swoole,
or a scheduler outside PHP - it silently degrades to `array` and nothing is fixed. One
line inside a request settles it:

```php
Log::debug('slogger: in a fiber?', ['yes' => Fiber::getCurrent() !== null]);
```

If that is `false`, write a store instead.

### Writing your own store

Two methods, no dependencies:

```php
namespace App\Tracing;

use SLoggerLaravel\Context\TraceContextInterface;

class CoroutineTraceContext implements TraceContextInterface
{
    public function get(string $key, mixed $default = null): mixed { /* ... */ }

    public function set(string $key, mixed $value): void { /* ... */ }
}
```

Then `SLOGGER_CONTEXT=App\Tracing\CoroutineTraceContext`. Four things it has to honour:

- **The store is a singleton and holds no state of its own.** It works out where to look
  on every call. Watchers are built once at bootstrap and keep the objects they were
  given, so a store that decided its scope in its constructor would keep answering for
  whichever unit of work happened to build it.
- **A key holding `null` is not an absent key.** `get()` must return `null` for the first
  and the default only for the second. Writing `$value ?? $default` conflates them, and
  "no parent trace" is written as `null`.
- **Writing must actually write.** If the underlying context has a "do not replace an
  existing key" mode, do not use it: a store that quietly keeps the first value ever
  written to a key reproduces the bug this exists to fix.
- **Values must come back by value.** Everything kept here is an array of scalars and
  `Carbon` instances, and the package clones a `Carbon` before it stores or changes one,
  so handing a unit of work a shallow copy of the array is enough - but a store that
  hands two units the same mutable object shares their state again.

There is deliberately no way to remove a key. A store whose reads fall through to an
enclosing unit of work - a coroutine context with inheritance usually does - would
uncover the enclosing unit's value; "there is nothing here" is written as `null` or as
an empty list instead.

### What `fiber` does not do

Reads do not fall through to the fiber that created the current one. PHP cannot say
which fiber that was, and guessing is how unrelated traces get stitched into one tree.

The consequence is worth knowing before you switch: **a fiber started inside a traced
request begins with no state of its own, so child traces pushed from inside it are
dropped** - a query, a log line or an event recorded there has no open parent trace in
that fiber, and `push()` returns early. Parent traces started there are recorded, as
roots rather than nested under the request, and so is anything a watcher marks as able
to stand alone (`can_be_orphan`). If your host code runs fibers *inside* a unit of work
(amphp/revolt and anything built on them), `fiber` will cost you that telemetry; a store
that can name the enclosing coroutine will not.

The same applies to an outbound HTTP call whose response is handled somewhere other than
where the call was made. Guzzle's response hook runs wherever the promise is resolved:
with curl and `wait()` that is the fiber that made the call, and everything works - but
under a runtime that settles promises on a loop fiber of its own, the call is opened in
one unit of work and answered in another, and the trace is never closed. This is not
about which map the watcher keeps its entry in; the processor's own record of the call
lives with the unit that made it either way.

### A unit of work that never reports back

Any store that scopes state to a unit of work - this one, or your own - changes what
happens when a unit ends without saying so. Worth knowing before you switch.

An exception is not that case: the framework still fires `RequestHandled`, `JobFailed`
or `CommandFinished`, so the trace closes as failed and everything below is irrelevant.
The case is a unit **dropped while suspended** - the scheduler killed it, a timeout took
it, the process is shutting down.

Nothing the store holds is leaked. The state goes with the unit: PHP unwinds a
destroyed fiber's stack, and the store releases its map along with it - the open traces,
the parent id, the outbound calls, the values `add()` collected.

What is lost is the closing update. The parent trace stays `started` in the backend, and
the outbound calls it left in flight are never tagged `__interrupted`, because the
processor's sweeps only ever see the current unit and no later unit can reach them. With
`array` a later request or job in the same process closed those calls once they were more
than five minutes old - the parent trace stayed `started` there too - which is the one
thing this gives up.

It cannot be fixed from inside the package. Cleanup during a fiber's destruction is not
allowed to do I/O - `Fiber::suspend()` throws `Cannot suspend in a force-closed fiber`
from a `finally` there, and `Cannot suspend outside of a fiber` from a destructor after
it - while dispatching a trace is exactly a suspension point under such a runtime.

**Age them out on the receiver instead**: a trace with no update for some minutes is
over, whatever happened to the process that started it. That is the only place still
looking after the process is gone, and it covers a fatal error or an out-of-memory kill
too, which nothing running inside the process ever will.

### Durations in a long-lived process

`LARAVEL_START` is defined once, in the entry script, where the *process* begins. Under
php-fpm the process is this request, the bootstrap it measures is this request's, and
both `boot_time` and the duration are counted from it. Under a server that boots once and
then serves for hours - Octane, RoadRunner, FrankenPHP's worker mode, a coroutine runtime
- it is the worker's start, and counting from it reports the worker's uptime as every
request's duration: the same number on every trace, growing all day.

So the constant is taken only where it is this request's own start. A process booted from
the console and answering HTTP is a long-lived server whatever it says about itself, and
never takes it. Under any other SAPI it is spent by the first request the process traces
and by no other. Everything else measures from the moment this package's middleware saw
the request - taken inside that request's own flow, so it is the request's own - and
reports `boot_time` as `-1`, since the boot it did not wait through is not its to claim.

Never from `Kernel::requestStartedAt()`, whichever runtime: it is one field for a whole
process, and a request starting alongside replaces it with a later one - a start in the
future, which is a negative duration.

Two things this leaves out, both small and both unavoidable from inside a package. Where
the constant is not taken, the stretch from entering the kernel to reaching this
middleware - the global middleware in front of it - is not counted; a runtime that wants
it back has to hand over the moment it accepted the request, because there is no earlier
point to read in a worker that booted hours ago (`REQUEST_TIME_FLOAT` is the process's
start there too). And under a worker whose SAPI is not the console, its first request
counts a boot it did not wait through: one trace per worker, and nothing that can tell
that boot from a slow one.

### What is per unit of work, and what is not

Per unit of work: the current parent trace id, the stack of open parent traces, the
pause flag, the map of detached (outbound) traces, the requests and commands a parent
watcher has open, the outbound calls the HTTP-client watcher has open, the jobs the job
watcher is processing, and the values `add()` collected.

Per process, on purpose: which watchers are enabled, the callbacks registered with
`add(fn() => ...)`, and the trace dispatcher's batching buffer - per unit it would stop
batching and multiply the jobs.

Those callbacks are shared, which is what makes them rules rather than values - so
register them once, from a service provider, and let them read what they need when they
run: `add('tenant', fn() => tenant()?->id())`. A closure registered per request, closing
over that request's own objects, is evaluated for every other unit's traces too, and
puts one request's data on another's - the very thing the rest of this section is about.
A plain value is this unit's own and is safe anywhere.

The socket client is neither: it keeps a small pool of connections - the one it was
built with, plus any it opens through `Connection::fresh()` - and a sender holds one from
the first byte written to the last byte read. A process sending one batch at a time opens
exactly one and keeps it, as before; concurrent senders each get one of their own rather
than interleaving frames on a shared stream, and up to eight are kept for reuse, the rest
closed on the way back. None of it is aware of fibers, so it holds for any way of running
things at once.

Whether senders can get inside each other's exchange at all is a property of the runtime,
not of this package: a bare PHP fiber suspends only where it says so, and `Connection`
says so nowhere. A runtime that turns a stream call into a suspension point - which is
what makes coroutines worth having in the first place - can. The pool costs nothing where
they cannot.

### A watcher that concurrency does not suit

**The dump watcher** swaps `VarDumper`'s handler, which is global to PHP, for the length
of one `dump()`. A `dump()` from another unit of work inside that window is not traced.
Harmless, but it is telemetry you will not see.

## Dispatchers

### Queue dispatcher

Traces are sent by `SendTracesJob`, queued on `SLOGGER_DISPATCHER_QUEUE_CONNECTION` and
`SLOGGER_DISPATCHER_QUEUE_NAME`. The package does not run workers for it: keep at least one
worker on that connection and queue the way you keep any other queue running -
Supervisor, systemd, Horizon:

```bash
php artisan queue:work slogger-rabbitmq --queue=slogger
```

The job carries its own tries and backoff, which take precedence over the worker's
options, so the worker needs neither `--tries` nor `--backoff`. `queue:work` is excepted
from the command watcher by default, and so is `horizon:work`, the worker under Horizon:
a worker does not trace itself.

- Parent and orphan traces are queued immediately, one job each.
- Child traces are buffered and queued in groups of 5; every trace update - a parent
  closing - is queued at once, together with whatever is buffered.
- What is still buffered is queued when the process ends.
- A failure to enqueue never reaches the application: the batch is dropped, and a
  warning (`dropped N trace dispatches`, at most one a minute) goes to the SLogger log
  channel.

### Local proxy

The optional Go proxy keeps a durable local buffer between the queue workers and SLogger. PHP
acknowledges a batch only after the proxy has appended it to disk and synced the file; the proxy
then delivers it to SLogger itself. Delivery is **at least once**: a crash after a send but before
the offset is saved can produce a duplicate, not a gap.

The release currently provides a **Linux amd64** binary. Install the asset matching the installed
package version:

```bash
php artisan slogger:local-proxy:download
vendor/bin/slogger-local-proxy -version
```

The default destination is `vendor/bin/slogger-local-proxy`. Use `--release=2.3.0` to select another
release explicitly, `--path=/custom/destination` for a different binary destination, and
`--force` to replace an existing binary after a package upgrade. The command verifies the release
SHA-256 before atomically replacing the previous binary.

Configure and enable it:

```dotenv
SLOGGER_TOKEN=your-api-token
SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE=proxy
SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL=tcp://your-slogger-host:port
SLOGGER_LOCAL_PROXY_TRACES_PATH=storage/slogger/traces
SLOGGER_LOCAL_PROXY_RETENTION_HOURS=24
```

- `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE` is `direct` by default. `direct` keeps the old
  behaviour: PHP connects straight to `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL`. In `proxy` mode
  PHP connects to the local Unix socket instead, while the Go process reads the same URL as its
  upstream. Switching modes does not require changing the URL.
- `SLOGGER_LOCAL_PROXY_TRACES_PATH` is relative to the application root. Leading and trailing
  slashes are ignored: `/storage/slogger/traces/` and `storage/slogger/traces` are equivalent and
  both mean `<project-root>/storage/slogger/traces`. A missing setting uses the default; explicitly
  empty values, paths containing only dot components and `..` are rejected.
- The socket filename is always `local-proxy.sock` inside that directory; neither the local socket
  URL nor its filename is configurable. Run PHP workers and the proxy as the same user (or grant
  equivalent access): the directory, socket and data files use owner-only permissions.
- `SLOGGER_LOCAL_PROXY_RETENTION_HOURS` defaults to 24 and accepts values from 1 through 2,562,047
  hours (the safe `time.Duration` range in hours). Expired files are removed even when their
  traces have not been sent yet, so increase the retention when planning for a longer outage.

A Supervisor example:

```ini
[program:slogger-local-proxy]
directory=/path/to/application
command=/path/to/application/vendor/bin/slogger-local-proxy -env /path/to/application/.env
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=10
user=application
redirect_stderr=true
stdout_logfile=/var/log/supervisor/slogger-local-proxy.log
```

Apply it with `supervisorctl reread && supervisorctl update`. Real process environment variables
override values from `.env`. The process takes an exclusive `flock`; a second launch fails instead
of replacing the active socket. SIGINT/SIGTERM stop acceptance and allow up to five seconds for
graceful shutdown.

The traces directory contains hourly files named `YYYY_MM_DD_HH`, `local-proxy-state.json`, the
lock/socket and error logs. Sent offsets are saved atomically. A sealed hourly file is deleted once
fully sent; an active file is not deleted merely because the sender has caught up with its current
end. Cleanup runs at startup and every ten minutes.

Outgoing batches contain at most 200 create/update operations and 4 MiB of JSON. A full batch is sent
immediately; an incomplete batch is sent at most once per second. Larger input records are split at
operation boundaries without discarding unknown trace fields. Batch size accounting includes the
protocol's second JSON encoding and its escaping, rather than counting only raw trace bytes. Each
individual operation must also fit within 4 MiB on the wire. Ingestion validates the payload format
and this limit before append/ACK; an invalid connection is closed without writing the record. An
unsendable operation already present on disk remains fail-stop instead of being silently discarded.
During shutdown, records accepted into the writer queue are allowed to finish their append and ACK
within the process-wide five-second hard timeout.

The local listener accepts up to 32 concurrent connections and holds at most 256 MiB of **input frame
buffers**, covering active and queued reads. A single frame remains limited to 64 MiB. The budget is
acquired before the payload buffer is allocated and released after the exchange. `Store.Append` makes
a separate record copy (up to 64 MiB plus its header), and sender allocations are outside this input
budget. When all connection slots are occupied, a new connection is closed immediately before the
handshake instead of waiting silently; the PHP job observes the failure and follows its existing
retry policy. A later connection can authenticate normally once a slot is released.

Errors go to `local-proxy-errors-YYYY_MM_DD.logs` in the same directory and to stderr. Daily error
logs are kept for three days. Tokens and trace payloads are never written to them. Upstream
connection timeout is 10 seconds, write and read timeouts are 20 seconds each. After a failed
connect/auth/send/read, the same batch is retried with a new handshake after three seconds and the
error is logged; the saved offset does not move.

Once per second the proxy atomically writes `local-proxy-metrics.json` in the traces directory. This
JSON snapshot includes process heap/RSS/goroutines, connections and slots, writer queue length and
capacity, input-frame budget, appended records/bytes, sent batches/operations/bytes, failed send
attempts, sender state, hourly file count/bytes and error-log count. It contains operational metadata
only, never tokens or trace payloads. The file uses owner-only permissions and is not treated as a
trace file by retention cleanup. Counters are process-lifetime values and reset when the proxy
restarts. Use `collected_at` to judge snapshot freshness. A continuous series of metrics publication
errors is logged at most once per minute, regardless of changing temporary paths in the error text.

#### Metrics command and alerting

The same `local-proxy-metrics.json` snapshot can be watched from the console:

```bash
php artisan slogger:local-proxy:metrics          # line format, one snapshot per second
php artisan slogger:local-proxy:metrics json     # pretty JSON, screen cleared each poll
php artisan slogger:local-proxy:metrics line --interval=5
php artisan slogger:local-proxy:metrics --once   # single snapshot; exit code 1 when unavailable
```

- The first argument is the format: `line` (the default) prints
  `collected_at=...;process.goroutines=21;sender.sent_operations_total=98;pending_bytes=512`,
  one snapshot per line — a stream to `tail` and `grep`. `json` clears the terminal and
  prints the beautified snapshot each poll.
- `--interval` is the poll frequency in seconds (default `1`, fractional values from `0.1`
  to `3600` accepted). Stop the loop with Ctrl+C.
- `--once` prints a single snapshot and exits non-zero when the file is missing, unreadable
  or stale, which makes it usable from monitoring checks.
- A snapshot older than `SLOGGER_LOCAL_PROXY_METRICS_MAX_SNAPSHOT_AGE` (default 10 seconds)
  is reported as stale rather than shown: the proxy is dead or has stopped writing. A
  timestamp materially in the future counts as stale too.

`pending_bytes` is not part of the proxy's file — the reader computes it from durable
state: the sender position in `local-proxy-state.json` against the hourly trace files
still on disk (only regular files count; a symlink or a directory with an hourly name is
skipped, as on the Go side). Unlike the process-lifetime counters it survives a proxy
restart. The state file is compared by identity (inode) before and after the scan, with
the first descriptor held open until then — the proxy saves state atomically, and the
pinned inode cannot be recycled by two saves in between, so a stable identity means a
stable offset for the whole read; a moving state is retried once and then reported as
unknown. Appends landing mid-read can only under-report; a file deleted mid-read without
a state change (an expired file removed by retention) can inflate a single reading, and
the next poll self-corrects. These guarantees hold while only the proxy writes the traces
directory. `pending_bytes` is `null` when the backlog cannot be known (the directory does
not scan, or the state file is unreadable).

The command reads the file through `SLoggerLaravel\LocalProxy\LocalProxyMetricsReader`,
which is reusable on its own: `read()` returns a `LocalProxyMetricsObject` or `null`.

**Alerting.** While the command runs, each fresh snapshot is checked against two thresholds,
and a crossing fires an event:

```dotenv
SLOGGER_LOCAL_PROXY_ALERTING_ENABLED=true
SLOGGER_LOCAL_PROXY_ALERT_MAX_PENDING_BYTES=104857600
SLOGGER_LOCAL_PROXY_ALERT_MAX_MINUTES_WITHOUT_SEND=5
```

- `SLOGGER_LOCAL_PROXY_ALERT_MAX_PENDING_BYTES` (default 100 MiB) — unsent trace data still
  on disk, the `pending_bytes` described above. Crossing fires
  `LocalProxyBacklogExceededEvent`.
- `SLOGGER_LOCAL_PROXY_ALERT_MAX_MINUTES_WITHOUT_SEND` (default 5) — `sent_operations_total`
  not growing for this many minutes fires `LocalProxySendStalledEvent`. The stall is measured
  in the proxy's own `uptime_seconds`, so a wall-clock jump on the host does not read as
  minutes without a send. A proxy restart — detected by the counter dropping or the uptime
  rolling back — restarts the clock instead of firing.

Each event fires once per crossing and re-arms when the value returns to normal — a running
command does not repeat the alert every second. The events ship with no listeners; attach
yours through the existing `listeners` section:

```php
'listeners' => [
    \SLoggerLaravel\Events\LocalProxyBacklogExceededEvent::class => [
        \App\Listeners\NotifyOpsAboutSLoggerBacklog::class,
    ],
    \SLoggerLaravel\Events\LocalProxySendStalledEvent::class => [
        \App\Listeners\NotifyOpsAboutSLoggerStall::class,
    ],
],
```

Delivery is **at least once, so keep listeners idempotent**. A listener that throws is
reported to the application's error handler, the alert is not marked as delivered, and the
next poll retries the whole dispatch — including any earlier listener that already ran
successfully for that crossing.

Listeners from this section are registered only while `SLOGGER_ENABLED=true`, so alerting
delivery requires the package to be enabled even though the metrics command itself runs
regardless.

For an upgrade, run `php artisan slogger:local-proxy:download --force` and restart the Supervisor
program. To roll back, set `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE=direct` and stop the proxy;
the upstream URL stays unchanged. Unsent proxy files are not migrated or drained automatically by
that switch.

### Memory dispatcher

Keeps every trace in the process, unmasked and unsent, until `flush()`;
`findCreating()`, `findUpdating()` and `totalCount()` are there for test assertions.
Intended for tests and local development, not for long-running processes.

## Testing

The suite runs in Docker through the makefile:

```bash
make setup                # once: build, start, composer install
make test                 # phpunit
make check                # cs-fixer + phpstan + phpunit
make proxy-check          # gofmt + go vet + race tests + Go build, in the PHP container
make proxy-integration    # real PHP client -> Go proxy -> fake SLogger
make check-laravel-all    # `check` on Laravel 10.26.* (the floor), 10, 11 and 12
```

`vendor/bin/phpunit` works as well with the dependencies installed locally. `phpunit.xml`
enables SLogger with the `memory` dispatcher, `BaseTestCase` sets in-memory sqlite and a
`sync` queue connection, and testbench loads `workbench/config/slogger.php`.

## Troubleshooting

- No traces: check `SLOGGER_ENABLED=true`, that the watcher is enabled and the
  middleware applied to the routes, and the SLogger log channel for
  `dropped N trace dispatches` - `SLOGGER_DISPATCHER_QUEUE_CONNECTION` unset, for one.
- Queue dispatcher not sending: a worker has to consume that connection *and* queue
  (`--queue=slogger`). Send errors never reach the application's error reporting: look in
  the SLogger log channel for `dropped N traces`, `auth failed` (the token) or socket
  errors (`SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL`, the receiver being up).
- Local proxy not sending: inspect `local-proxy-errors-YYYY_MM_DD.logs` in the configured traces
  directory. Common causes are upstream auth/connect failures, an already-held lock, an invalid
  state/file or insufficient disk space. The socket must be accessible to both the proxy and queue
  worker user.

## Upgrading

### From 2.2

- The optional local Go proxy is added. Existing applications keep direct socket delivery:
  `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE` defaults to `direct`, so no action is required unless
  you enable the proxy. See [Local proxy](#local-proxy) for installation and Supervisor setup.
- A published `config/slogger.php` does not gain `dispatchers.queue.api_clients.socket.type` or
  `local_proxy` automatically. Republish it or add those keys before enabling `proxy` mode.

### From 2.1

- **`slogger:dispatcher:start` and `slogger:dispatcher:stop` are gone.** The package no
  longer runs queue workers of its own: run them the way you run any other queue -
  Supervisor, systemd, Horizon - see [Queue dispatcher](#queue-dispatcher). Stop the old
  dispatcher before upgrading, and drop it from your process manager.
  `SLOGGER_DISPATCHER_QUEUE_WORKERS_COUNT` is no longer read, and `storage/slogger/`,
  which held only the dispatcher's state, can be deleted. `horizon:work` joined the
  command watcher's default `excepted` list; a published config does not get it - add it
  by hand if you run Horizon.
- **Profiling is gone**, with `SLOGGER_PROFILING_ENABLED` and the `profiling` config
  section. A leftover in a published config is ignored.
- `ext-pcntl`, `ext-posix` and `symfony/process` are no longer required by the package.
- API changes if you extend the core: `TraceDispatcherInterface::getProcessor()`,
  `DispatcherProcessorInterface`, `QueueDispatcherProcessor`,
  `DispatcherQueueConfig::getWorkersNum()`, `WatchersConfig::profilingEnabled()` and
  `LocalStorage` are gone, `TraceUpdateObject` lost its `profiling` argument, and
  `QueueDispatcher` no longer takes the application.
- **A notification's routes moved** from the top-level `recipients` to
  `delivery.recipients`. At the top level no key list reached them, so a phone number
  there was sent unmasked. Anything reading the field on the receiving side needs
  updating.

### To 2.1

The default behaviour does not change, and neither does any public method. Two
constructors did, which matters only if you build one yourself or override one in a
subclass:

| Class | Change |
| --- | --- |
| `TraceIdContainer` | now takes `TraceContextInterface`; it had no constructor before |
| `HttpMiddleware` | now takes `TraceIdContainer` as a second argument |

`Connection` gained `fresh()`, and `SocketClient` and `ApiClientFactory` kept their
arguments.

`Processor`, `RequestWatcher`, `CommandWatcher`, `JobWatcher`, `HttpClientWatcher` and
`TraceDataComplementer` each take a `TraceContextInterface` as their **last** argument.
All of these are resolved from the container, so nothing else has to change.

### To 2.0

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
- API changes if you build formatters yourself: `RequestDataFormatter` lost its
  `requestHeaders`, `requestParameters`, `responseHeaders` and `responseFields`
  arguments along with the matching `add*()` methods, and
  `MaskHelper::maskArrayByList()`/`maskArrayByPatterns()` are gone.
  `MaskHelper::maskValue()` now masks a string whole; `maskValuePartially()` is the
  one that keeps a couple of characters.
- API changes if you extend the core: `Processor::stopDetached()` is gone - `stop()`
  closes a parent trace whichever way it was started - and so are
  `Processor::handleSeparateTracing()` and `Processor::registerWatcher()`, which the
  service provider now does itself. `DispatcherProcessorInterface` gained
  `getChildCommandName()`: the master saves that name and looks its children up by it,
  so it has to be what the process table will show.

## License

MIT
