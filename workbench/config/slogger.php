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
    'enabled' => env('SLOGGER_ENABLED', false),

    'token' => env('SLOGGER_TOKEN'),

    'dispatchers' => [
        'default' => env('SLOGGER_DISPATCHER', 'queue'),

        'queue' => [
            'connection'  => env('SLOGGER_DISPATCHER_QUEUE_CONNECTION', $defaultQueueConnection),
            'name'        => env('SLOGGER_DISPATCHER_QUEUE_NAME', 'slogger'),
            'workers_num' => env('SLOGGER_DISPATCHER_QUEUE_WORKERS_COUNT', 3),

            'api_clients' => [
                'default' => env('SLOGGER_DISPATCHER_QUEUE_API_CLIENT', 'http'),

                'http' => [
                    'url' => env('SLOGGER_DISPATCHER_QUEUE_HTTP_CLIENT_URL'),
                ],

                'socket' => [
                    'url' => env('SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL'),
                ],
            ],
        ],
    ],

    'profiling' => [
        'enabled' => env('SLOGGER_PROFILING_ENABLED', false),
    ],

    'log_channel' => env('SLOGGER_LOG_CHANNEL', 'daily'),

    'listeners' => [
        WatcherErrorEvent::class => [
            WatcherErrorListener::class,
        ],
    ],

    'data_completer' => [
        'excluded_file_masks' => [
            //
        ],
    ],

    'watchers_customizing' => [
        'requests' => [
            'header_parent_trace_id_key' => env(
                'SLOGGER_REQUESTS_HEADER_PARENT_TRACE_ID_KEY',
                'x-parent-trace-id'
            ),

            /** url_patterns */
            'excepted_paths'             => [
                //
            ],

            'input' => [
                /** url_patterns */
                'full_hiding'        => [
                    //
                ],
                /** url_pattern => keys */
                'headers_masking'    => [
                    '*' => [
                        'authorization',
                        'cookie',
                        'x-xsrf-token',
                    ],
                ],
                /** url_pattern => key_patterns */
                'parameters_masking' => [
                    '*' => [
                        '*token*',
                        '*password*',
                    ],
                ],
            ],

            'output' => [
                /** url_patterns */
                'full_hiding'     => [
                    //
                ],

                /** url_pattern => keys */
                'headers_masking' => [
                    '*' => [
                        'set-cookie',
                    ],
                ],

                /** url_pattern => key_patterns */
                'fields_masking'  => [
                    '*' => [
                        '*token*',
                        '*password*',
                    ],
                ],
            ],
        ],

        'commands' => [
            'excepted' => [
                'queue:work',
                'queue:listen',
                'schedule:run',
            ],
        ],

        'jobs' => [
            'excepted' => [
                SendTracesJob::class,
            ],
        ],

        'models' => [
            /** model_class => field_patterns */
            'masks' => [
                '*' => [
                    '*token*',
                    '*password*',
                ],
            ],
        ],

        'events' => [
            'ignore_events'    => [
                //
            ],
            'serialize_events' => [
                //
            ],
            'can_be_orphan'    => [
                //
            ],
        ],
    ],

    'watchers' => [
        [
            'class'   => RequestWatcher::class,
            'enabled' => env('SLOGGER_LOG_REQUESTS_ENABLED', false),
        ],
        [
            'class'   => CommandWatcher::class,
            'enabled' => env('SLOGGER_LOG_COMMANDS_ENABLED', false),
        ],
        [
            'class'   => DatabaseWatcher::class,
            'enabled' => env('SLOGGER_LOG_DATABASE_ENABLED', false),
        ],
        [
            'class'   => LogWatcher::class,
            'enabled' => env('SLOGGER_LOG_LOG_ENABLED', false),
        ],
        [
            'class'   => ScheduleWatcher::class,
            'enabled' => env('SLOGGER_LOG_LOG_ENABLED', false),
        ],
        [
            'class'   => JobWatcher::class,
            'enabled' => env('SLOGGER_LOG_JOBS_ENABLED', false),
        ],
        [
            'class'   => ModelWatcher::class,
            'enabled' => env('SLOGGER_LOG_MODEL_ENABLED', false),
        ],
        [
            'class'   => GateWatcher::class,
            'enabled' => env('SLOGGER_LOG_GATE_ENABLED', false),
        ],
        [
            'class'   => EventWatcher::class,
            'enabled' => env('SLOGGER_LOG_EVENT_ENABLED', false),
        ],
        [
            'class'   => MailWatcher::class,
            'enabled' => env('SLOGGER_LOG_MAIL_ENABLED', false),
        ],
        [
            'class'   => NotificationWatcher::class,
            'enabled' => env('SLOGGER_LOG_NOTIFICATION_ENABLED', false),
        ],
        [
            'class'   => CacheWatcher::class,
            'enabled' => env('SLOGGER_LOG_CACHE_ENABLED', false),
        ],
        [
            'class'   => DumpWatcher::class,
            'enabled' => env('SLOGGER_LOG_DUMP_ENABLED', false),
        ],
        [
            'class'   => HttpClientWatcher::class,
            'enabled' => env('SLOGGER_LOG_HTTP_ENABLED', false),
        ],
    ],
];
