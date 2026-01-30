
# SLogger for laravel 

## Installation

### App

```bash
php artisan vendor:publish --tag=slogger-laravel
```

### ENV

```dotenv
# slogger
SLOGGER_ENABLED=false

SLOGGER_TOKEN=

## slogger.dispatcher
# one of: queue, file
SLOGGER_DISPATCHER=queue

## slogger.dispatcher.queue
SLOGGER_DISPATCHER_QUEUE_CONNECTION="${QUEUE_CONNECTION}"
SLOGGER_DISPATCHER_QUEUE_NAME=slogger
SLOGGER_DISPATCHER_QUEUE_WORKERS_COUNT=3
# http, socket
SLOGGER_DISPATCHER_QUEUE_API_CLIENT=http
SLOGGER_DISPATCHER_QUEUE_HTTP_CLIENT_URL=http://0.0.0.0:0001
SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL=tcp://0.0.0.0:0002

## slogger.logging
SLOGGER_LOG_CHANNEL=daily

## slogger.profiling
SLOGGER_PROFILING_ENABLED=true

SLOGGER_REQUESTS_HEADER_PARENT_TRACE_ID_KEY=x-parent-trace-id

## slogger.watchers
SLOGGER_LOG_REQUESTS_ENABLED=true
SLOGGER_LOG_COMMANDS_ENABLED=true
SLOGGER_LOG_DATABASE_ENABLED=true
SLOGGER_LOG_LOG_ENABLED=true
SLOGGER_LOG_SCHEDULE_ENABLED=true
SLOGGER_LOG_JOBS_ENABLED=true
SLOGGER_LOG_MODEL_ENABLED=true
SLOGGER_LOG_GATE_ENABLED=true
SLOGGER_LOG_EVENT_ENABLED=true
SLOGGER_LOG_MAIL_ENABLED=true
SLOGGER_LOG_NOTIFICATION_ENABLED=true
SLOGGER_LOG_CACHE_ENABLED=true
SLOGGER_LOG_DUMP_ENABLED=true
SLOGGER_LOG_HTTP_ENABLED=true
```

## Requests (env.SLOGGER_LOG_REQUESTS_ENABLED)

For requests watcher you can use the middleware

```php
\SLoggerLaravel\Middleware\HttpMiddleware::class
```

## Guzzle (env.SLOGGER_LOG_HTTP_ENABLED)

For guzzle requests you can use the factory

```php
new \GuzzleHttp\Client([
    'base_uri' => 'https://url.com',
    'handler'  => app(\SLoggerLaravel\Guzzle\GuzzleHandlerFactory::class)->prepareHandler(
        (new \SLoggerLaravel\RequestPreparer\RequestDataFormatters())
            ->add(
                new \SLoggerLaravel\RequestPreparer\RequestDataFormatter(
                    urlPatterns: ['*'],
                    requestHeaders: [
                        'authorization',
                    ]
                )
            )
            ->add(
                new \SLoggerLaravel\RequestPreparer\RequestDataFormatter(
                    urlPatterns: [
                        '/api/auth/*',
                        '*sensitive/some/*',
                    ],
                    hideAllResponseData: true
                )
            )
    ),
])
```

## Dispatcher of traces

For the dispatcher usage you have to set env.SLOGGER_DISPATCHER_* according to the selected dispatcher (env.SLOGGER_DISPATCHER)

```bash
php artisan slogger:dispatcher:start
```

### queue
The queue dispatcher works like the Horizon (master and children processes of queue:work command).

.gitignore
```gitignore
storage/slogger-dispatcher-state*
```

## Profiling (only for an http client)

bash
```bash
pecl install xhprof
```

php.ini
```ini
[xhprof]
extension=xhprof.so
```

Set env.SLOGGER_PROFILING_ENABLED -> true
