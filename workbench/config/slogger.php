<?php

use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\SendTracesJob;
use SLoggerLaravel\Events\WatcherErrorEvent;
use SLoggerLaravel\Listeners\WatcherErrorListener;
use SLoggerLaravel\Watchers\Children\CacheWatcher;
use SLoggerLaravel\Watchers\Children\DatabaseWatcher;
use SLoggerLaravel\Watchers\Children\DumpWatcher;
use SLoggerLaravel\Watchers\Children\EventWatcher;
use SLoggerLaravel\Watchers\Children\GateWatcher;
use SLoggerLaravel\Watchers\Children\HttpClientWatcher;
use SLoggerLaravel\Watchers\Children\LogWatcher;
use SLoggerLaravel\Watchers\Children\MailWatcher;
use SLoggerLaravel\Watchers\Children\ModelWatcher;
use SLoggerLaravel\Watchers\Children\NotificationWatcher;
use SLoggerLaravel\Watchers\Children\ScheduleWatcher;
use SLoggerLaravel\Watchers\Parents\CommandWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

$defaultQueueConnection = env('QUEUE_CONNECTION');

return [
    // global enable/disable.
    'enabled' => env('SLOGGER_ENABLED', false),

    // api token for the backend.
    'token' => env('SLOGGER_TOKEN'),

    // trace id prefix. if empty, uses slugged app.name or "app".
    'trace_id_prefix' => env('SLOGGER_TRACE_ID_PREFIX', ''),

    // dispatcher selection and configuration.
    'dispatchers' => [
        // one of: queue, memory.
        'default' => env('SLOGGER_DISPATCHER', 'queue'),

        'queue' => [
            // queue worker connection and name.
            'connection' => env('SLOGGER_DISPATCHER_QUEUE_CONNECTION', $defaultQueueConnection),
            'name' => env('SLOGGER_DISPATCHER_QUEUE_NAME', 'slogger'),
            // number of worker processes.
            'workers_num' => env('SLOGGER_DISPATCHER_QUEUE_WORKERS_COUNT', 3),

            'api_clients' => [
                // default api client: http or socket.
                'default' => env('SLOGGER_DISPATCHER_QUEUE_API_CLIENT', 'http'),

                'http' => [
                    // base url for http backend.
                    'url' => env('SLOGGER_DISPATCHER_QUEUE_HTTP_CLIENT_URL'),
                ],

                'socket' => [
                    // socket address for socket backend (e.g. tcp://host:port).
                    'url' => env('SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL'),
                ],
            ],
        ],
    ],

    // profiling for http client traces (requires xhprof extension).
    'profiling' => [
        'enabled' => env('SLOGGER_PROFILING_ENABLED', false),
    ],

    // channel for internal errors/logs.
    'log_channel' => env('SLOGGER_LOG_CHANNEL', 'daily'),

    // internal listener for watcher errors.
    'listeners' => [
        WatcherErrorEvent::class => [
            WatcherErrorListener::class,
        ],
    ],

    // exclude files from trace backtraces (supports wildcard masks).
    'data_completer' => [
        'excluded_file_masks' => [
            //
        ],
    ],

    // header key for parent trace id propagation.
    'http_parent_trace_id_header_key' => env(
        'SLOGGER_REQUESTS_HEADER_PARENT_TRACE_ID_KEY',
        'x-parent-trace-id'
    ),

    // watchers configuration (parents and children).
    'watchers' => [
        /**
         * PARENTS
         */

        [
            'class'   => CommandWatcher::class,
            'enabled' => env('SLOGGER_LOG_COMMANDS_ENABLED', false),
            'config'  => [
                // command names to ignore.
                'excepted' => [
                    'queue:work',
                    'queue:listen',
                    'schedule:run',
                ],
            ],
        ],
        [
            'class'   => RequestWatcher::class,
            'enabled' => env('SLOGGER_LOG_REQUESTS_ENABLED', false),
            'config'  => [
                // log only these url patterns. empty means all.
                'only_paths' => [
                    //
                ],

                // skip these url patterns.
                'excepted_paths' => [
                    //
                ],

                'input' => [
                    // apply input formatting only for these url patterns. empty means all.
                    'only_paths' => [
                        //
                    ],

                    // hide all request parameters for these url patterns.
                    'hidden_paths' => [
                        '*',
                    ],

                    // mask specific request headers by url pattern.
                    'headers_masking' => [
                        '*' => [
                            'authorization',
                            'cookie',
                            'x-xsrf-token',
                        ],
                    ],

                    // mask request parameters by url pattern.
                    'parameters_masking' => [
                        '*' => [
                            '*token*',
                            '*password*',
                        ],
                    ],
                ],

                'output' => [
                    // apply response formatting only for these url patterns. empty means all.
                    'only_paths' => [
                        //
                    ],

                    // hide all response data for these url patterns.
                    'hidden_paths' => [
                        '*',
                    ],

                    // mask specific response headers by url pattern.
                    'headers_masking' => [
                        '*' => [
                            'set-cookie',
                        ],
                    ],

                    // mask response fields by url pattern.
                    'fields_masking' => [
                        '*' => [
                            '*token*',
                            '*password*',
                        ],
                    ],
                ],
            ],
        ],
        [
            'class'   => JobWatcher::class,
            'enabled' => env('SLOGGER_LOG_JOBS_ENABLED', false),
            'config'  => [
                // job classes to ignore.
                'excepted' => [
                    SendTracesJob::class,
                ],
            ],
        ],

        /**
         * CHILDREN
         */

        [
            'class'   => CacheWatcher::class,
            'enabled' => env('SLOGGER_LOG_CACHE_ENABLED', false),
        ],
        [
            'class'   => DatabaseWatcher::class,
            'enabled' => env('SLOGGER_LOG_DATABASE_ENABLED', false),
        ],
        [
            'class'   => DumpWatcher::class,
            'enabled' => env('SLOGGER_LOG_DUMP_ENABLED', false),
        ],
        [
            'class'   => EventWatcher::class,
            'enabled' => env('SLOGGER_LOG_EVENT_ENABLED', false),
            'config'  => [
                // track only these event names (empty means all).
                'only_events' => [
                    //
                ],
                // ignore these event names.
                'ignore_events' => [
                    //
                ],
                // events to serialize into payload.
                'serialize_events' => [
                    //
                ],
                // events that can be orphaned (no parent trace).
                'can_be_orphan' => [
                    //
                ],
            ],
        ],
        [
            'class'   => GateWatcher::class,
            'enabled' => env('SLOGGER_LOG_GATE_ENABLED', false),
        ],
        [
            'class'   => HttpClientWatcher::class,
            'enabled' => env('SLOGGER_LOG_HTTP_ENABLED', false),
        ],
        [
            'class'   => LogWatcher::class,
            'enabled' => env('SLOGGER_LOG_LOG_ENABLED', false),
        ],
        [
            'class'   => MailWatcher::class,
            'enabled' => env('SLOGGER_LOG_MAIL_ENABLED', false),
        ],
        [
            'class'   => ModelWatcher::class,
            'enabled' => env('SLOGGER_LOG_MODEL_ENABLED', false),
            'config'  => [
                // model field masks by model class.
                'masks' => [
                    '*' => [
                        '*token*',
                        '*password*',
                    ],
                ],
            ],
        ],
        [
            'class'   => NotificationWatcher::class,
            'enabled' => env('SLOGGER_LOG_NOTIFICATION_ENABLED', false),
        ],
        [
            'class'   => ScheduleWatcher::class,
            'enabled' => env('SLOGGER_LOG_LOG_ENABLED', false),
        ],
    ],
];
