# SLogger Laravel

**English** | [Русский](README.ru.md)

SLogger Laravel is a tracing/observability package for Laravel apps. It records request/command/job/event/etc. traces and delivers them to a remote backend via configurable dispatchers.

This README documents installation, configuration, watchers, masking, dispatchers, profiling, and usage patterns.

## Upgrading to 1.3

Masking moved out of the traced application and into the dispatcher job.

- **Per-watcher masking is gone.** `input.headers_masking`, `input.parameters_masking`,
  `output.headers_masking`, `output.fields_masking` and the model watcher's `masks` are
  no longer read. Leftovers in a published config are ignored, not an error - which
  means any key you added there stops being masked. **Port your own keys into
  `masking.full_keys`**; the shipped defaults cover the shipped defaults, not `ssn`,
  `iban`, `card` or anything else you added yourself.
- **Two global lists instead**, under `masking.full_keys` and `masking.partial_keys`. A
  published config is merged with the package's own now, so the defaults apply without
  republishing; add the section to your config only to change it. A missing list falls
  back to the package's own config file, so a stale config cache cannot leave you with
  no masking at all.
- **A masked secret keeps nothing.** A value under a `masking.full_keys` key becomes
  `********` - the previous release left the first and last third of every string
  readable, which for a token or a password is not a mask. Values under
  `masking.partial_keys` (addresses, phone numbers, names) keep two characters at each
  end, which is what tells two records apart.
- **Restart the slogger workers together with the application.** Masking happens in the
  worker now, so a worker still running 1.2.x drains a 1.3 queue and ships those batches
  unmasked. Deploy the workers first, or drain the slogger queue across the switch.
- **The slogger queue now holds unmasked trace data.** Masking happens on the way out,
  so whatever the watchers collected sits in the queue store until the batch is sent.
  Give that queue the retention and access rules the data deserves - a separate Redis
  database or queue connection, and no long-lived `failed_jobs` rows for it.
- **Trace data changed shape** where the old shape put application data out of the
  masker's reach: cache values are nested under their cache key (`cache.<key>.value`),
  mail addresses are nested under `message` and carried as `email`/`full_name` pairs
  instead of address-as-key, and the query string is split off the url into `query` and
  `query_string`. Anything consuming those fields on the receiving side needs updating.
- **Laravel 10.17** is the new floor. `src/` needs 10.12 (`JobTimedOut` landed there),
  but 10.17 is the oldest release the test suite can actually be installed against, and
  an untested floor is not a supported one.
- API changes if you build formatters yourself: `RequestDataFormatter` lost its
  `requestHeaders`, `requestParameters`, `responseHeaders` and `responseFields`
  arguments along with the matching `add*()` methods, and
  `MaskHelper::maskArrayByList()`/`maskArrayByPatterns()` are gone.
  `MaskHelper::maskValue()` now masks a string whole; `maskValuePartially()` is the
  one that keeps a couple of characters.

## Requirements

- PHP >= 8.2
- Laravel 10.17+ (tested on 10, 11 and 12)
- Queue driver for `queue` dispatcher
- Optional: XHProf extension for profiling

## Installation

1) Install the package (via Composer in your app):

```bash
composer require slogger/slogger-laravel
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

### Profiling

```dotenv
SLOGGER_PROFILING_ENABLED=true
```

Enables XHProf profiling for HTTP client traces (see Profiling section).

### Request parent trace header

```dotenv
SLOGGER_REQUESTS_HEADER_PARENT_TRACE_ID_KEY=x-parent-trace-id
```

Allows linking child traces to parent requests via a custom header.

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

Watcher data highlights:
- `request`: url, method, action, headers/params, response (for JSON responses)
- `job`: connection, payload, status (`processed`, `failed`, `released_after_exception`, `timed_out`, `exception_occurred`), exception
- `event`: listeners, broadcast, optional serialized payload
- `model`: action, model class, key, changes
- `mail`: mailable/notification, queued, `message` (from/reply_to/to/cc/bcc as `email`/`full_name` pairs, subject)
- `notification`: notifiable, channel, queued, response
- `cache`: type, key, and `cache.<key>` (value, tags, expiration)
- `db`: query, bindings (always masked), time
- `http-client`: method, url, query/query_string, request/response (concurrent requests are traced independently, so `Http::pool()` works)
- `schedule`: command, description, cron, output
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
                'max_content_length' => 1048576,
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

Large JSON responses are skipped and marked with:

```json
{"__skipped": "response_too_large"}
```

## Masking Rules

Masking runs **in the dispatcher job**, right before a batch is sent, and never in the
traced application. Building a trace costs the application only what it takes to
collect and hand off the data; walking a payload key by key is paid for by the
dispatcher workers instead. Two consequences follow:

- **The queue holds unmasked trace data.** Whatever the watchers collected sits in the
  queue store until the batch is sent, so the slogger queue is as sensitive as the
  traces themselves: give it its own connection, keep its retention short, and do not
  let failed batches pile up in `failed_jobs`.
- The `memory` dispatcher never masks - it has no job. It is a development and testing
  aid and sends nothing anywhere.

Watchers do not mask. What they do at runtime is hide and truncate: `only_paths`,
`excepted_paths`, `hidden_paths`, `max_content_length`, per-watcher `excepted` lists.
The one exception is the database watcher: query bindings are positional, so no key list
can reach them, and it masks them where they are recorded. All of them, whatever their
type or length - nothing in a binding says whether it is a password or a page number,
and a PIN, an OTP and an account number are all short and numeric.

### The key lists

```php
'masking' => [
    // a value under a matching key is replaced whole - nothing of it survives
    'full_keys' => [
        'token', 'pass', 'auth', 'secret', 'private', 'apikey',
        'api_key', 'api-key', 'credential', 'sign', 'cookie',
    ],

    // a value under a matching key keeps two characters at each end
    'partial_keys' => [
        'email', 'phone', '_name', 'lastname', 'firstname', 'surname',
    ],
],
```

Both lists are case-insensitive substrings of a key; two empty lists turn masking off.
A key matches when it *contains* one of the substrings, so `customer_email`, `API_KEY`
and `lastName` are all matched. A match on a parent key applies to its subtree, so
`auth` covers `auth.method` too. A key in both lists is masked whole - the stricter
list wins.

The split is the point. A secret is worthless the moment any of it leaks, so
`masking.full_keys` replaces the value entirely: `********`, a fixed width, so the length of
the secret does not leak either. An address, a phone number or a name is mostly there
to tell two records apart, so `masking.partial_keys` keeps two characters at each end -
`john.doe@example.com` becomes `jo****************om`. **Never put a secret in
`partial_keys`**: what is left is enough to correlate records, and for a short value it
is enough to guess it.

A value that is a **string containing a JSON document** is decoded, masked and encoded
back: applications hand whole documents over as strings - an Eloquent `array` cast puts
one straight into a model's changes - and the key carrying such a string says nothing
about what is inside it. Only strings that start with `{` or `[` are parsed, and a
document in which nothing matched is kept byte for byte. A document in which something
matched is re-encoded, so its escaping is normalised and a number too large or too
precise for a PHP float loses precision.

A value under a **`query_string`** key is masked parameter by parameter rather than as
a whole, so `page=2&api_token=secret` keeps the page and loses the token. This is where
the request watchers put a url's query string: a url is also a tag and a title, and
nothing masks those.

**The top level of a trace's `data` is never masked.** That level belongs to the
watcher, not to the application: `connection_name`, `request`, `changes`, `context`,
`bindings` and so on are a fixed structure, and the traced data starts one level in.
Matching therefore begins inside it - `context.customer_email` and
`job.data.customer_email` are masked, while `connection_name` is left readable even
though it contains `_name`. Watchers whose own top level used to hold application data
were reshaped so this rule holds for them too: a cache value sits under its cache key
(`cache.<key>.value`, so the key itself is what the list matches against), and mail
addresses sit under `message` as `email`/`full_name` pairs.

The lists are deliberately blunt: they mask `sign` inside `assignee` and `auth` inside
`author`. Over-masking is the safe direction for telemetry; trim the list if a field you
need is caught by it.

### Masked values

Masked values keep basic types, so a masked payload stays shaped like the original:
- `null` -> `null`
- `bool` -> `false`
- `int` -> `0`
- `float` -> `0.0`
- `string` -> `********`, or two characters at each end for a partial mask
- arrays/objects -> `********`

An empty string is left as it is: a mask there would claim something had been hidden.

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

Formatters hide and truncate; sensitive values are masked later, by the
dispatcher job.

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

Stop the dispatcher:

```bash
php artisan slogger:dispatcher:stop
```

### Memory dispatcher

Stores traces in memory only. Intended for tests/local development.

## Storage

SLogger does not persist traces locally. The only local file is the dispatcher state file in:

```text
storage/slogger/dispatcher-state-*.json
```

You may want to ignore the folder:

```gitignore
storage/slogger/*
```

## Profiling (XHProf)

Only for HTTP client tracing.

1) Install extension:

```bash
pecl install xhprof
```

2) Enable in `php.ini`:

```ini
[xhprof]
extension=xhprof.so
```

3) Enable:

```dotenv
SLOGGER_PROFILING_ENABLED=true
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
