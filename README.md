# SLogger Laravel

**English** | [Русский](README.ru.md)

SLogger Laravel is a tracing/observability package for Laravel apps. It records request/command/job/event/etc. traces and delivers them to a remote backend via configurable dispatchers.

This README documents installation, configuration, watchers, masking, dispatchers, profiling, and usage patterns.

## Requirements

- PHP >= 8.2
- Laravel 10+ (tested), should work on Laravel 12
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
- `mail`: from/to/cc/bcc, subject, queued, mailable/notification
- `notification`: notifiable, channel, queued, response
- `cache`: type, key, tags, value
- `db`: query, bindings, time
- `http-client`: method, url, request/response (concurrent requests are traced independently, so `Http::pool()` works)
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

- `SendTracesJob` is **encrypted** (`ShouldBeEncrypted`), because the traces sit in the
  queue with whatever the watchers collected. This needs `APP_KEY`, which a Laravel
  application always has.
- The `memory` dispatcher never masks - it has no job. It is a development and testing
  aid and sends nothing anywhere.

Watchers do not mask. What they do at runtime is hide and truncate: `only_paths`,
`excepted_paths`, `hidden_paths`, `max_content_length`, per-watcher `excepted` lists.
The one exception is the database watcher: query bindings are positional, so no key list
can reach them, and it masks them where they are recorded.

### The key list

```php
'masking' => [
    // case-insensitive substrings of a key. an empty list turns masking off
    'keys' => [
        'token', 'pass', 'auth', 'email', 'phone', '_name', 'lastname',
        'firstname', 'surname', 'secret', 'private', 'apikey', 'api_key',
        'api-key', 'credential', 'sign', 'cookie',
    ],

    // keys written by the package itself, matched against the whole dotted path
    'excepted_keys' => [
        'connection_name',
    ],
],
```

A key matches when it *contains* one of the substrings, so `customer_email`, `API_KEY`
and `lastName` are all masked. Matching runs over the whole dotted path, so a match on
a parent key masks its subtree: `auth` masks `auth.method` too.

`excepted_keys` are wildcard masks of the whole dotted path. They exist because the
package writes keys of its own into trace data - `connection_name` contains `_name` but
describes the trace, not the traced data.

The list is deliberately blunt: it masks `sign` inside `assignee` and `auth` inside
`author`. Over-masking is the safe direction for telemetry; trim the list if a field you
need is caught by it.

### Masked values

Masked values keep basic types:
- `bool` -> `false`
- `int` -> `0`
- `float` -> `0.0`
- `string` -> masked string
- arrays/objects -> masked string

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
