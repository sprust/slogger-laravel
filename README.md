
# SLogger for laravel 

## Installation

### App

system:
```bash
php artisan vendor:publish --tag=slogger-laravel
```

.env:
```dotenv
# slogger
SLOGGER_ENABLED=false

SLOGGER_TOKEN=

## slogger.dispatcher
# one of: queue,transporter,file
SLOGGER_DISPATCHER=queue

## slogger.dispatcher.queue
SLOGGER_DISPATCHER_QUEUE_CONNECTION=${QUEUE_CONNECTION}
SLOGGER_DISPATCHER_QUEUE_NAME=slogger
SLOGGER_DISPATCHER_QUEUE_WORKERS_COUNT=3
# http, grpc (required slogger/grpc)
SLOGGER_DISPATCHER_QUEUE_API_CLIENT=http
SLOGGER_DISPATCHER_QUEUE_HTTP_CLIENT_URL=http://0.0.0.0:0001
SLOGGER_DISPATCHER_QUEUE_GRPC_CLIENT_URL=0.0.0.0:0002

## slogger.dispatcher.transporter
SLOGGER_DISPATCHER_TRANSPORTER_QUEUE_CONNECTION=${QUEUE_CONNECTION}
SLOGGER_DISPATCHER_TRANSPORTER_QUEUE_NAME=slogger-transporter
## slogger.dispatcher.transporter.bim
SLOGGER_DISPATCHER_TRANSPORTER_GRPC_PORT=
SLOGGER_DISPATCHER_TRANSPORTER_LOG_DIR=storage/logs/slogger/transporter
SLOGGER_DISPATCHER_TRANSPORTER_LOG_LEVELS=any
SLOGGER_DISPATCHER_TRANSPORTER_LOG_KEEP_DAYS=3
SLOGGER_DISPATCHER_TRANSPORTER_SLOGGER_SERVER_GRPC_URL=0.0.0.0:0002
SLOGGER_DISPATCHER_TRANSPORTER_RABBITMQ_USER=root
SLOGGER_DISPATCHER_TRANSPORTER_RABBITMQ_PASSWORD=
SLOGGER_DISPATCHER_TRANSPORTER_RABBITMQ_HOST=
SLOGGER_DISPATCHER_TRANSPORTER_RABBITMQ_PORT=
SLOGGER_DISPATCHER_TRANSPORTER_TRACE_TRANSPORTER_QUEUE_NAME=slogger-transporter
SLOGGER_DISPATCHER_TRANSPORTER_TRACE_TRANSPORTER_QUEUE_WORKERS_NUM=10

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

For requests watcher you can use the middleware

```php
\SLoggerLaravel\Middleware\HttpMiddleware::class
```

For guzzle requests you can use the factory

```php
new \GuzzleHttp\Client([
    'base_uri' => 'https://url.com',
    'handler'  => app(\SLoggerLaravel\Guzzle\GuzzleHandlerFactory::class)->prepareHandler(
        (new SLoggerRequestDataFormatters())
            ->add(
                new SLoggerRequestDataFormatter(
                    urlPatterns: ['*'],
                    requestHeaders: [
                        'authorization',
                    ]
                )
            )
            ->add(
                new SLoggerRequestDataFormatter(
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

### Transporter

For the transporter usage you have to set env.SLOGGER_DISPATCHER_TRANSPORTER_*

load bin file
```bash
php artisan slogger:transporter:load
```

start transporter (for example, for supervisor)
```bash
php artisan slogger:transporter:start
```

stop transporter
```bash
php artisan slogger:transporter:stop
```

.gitignore
```gitignore
strans
.env.strans.*
```

Set env.SLOGGER_DISPATCHER -> transporter

### Profiling

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
