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
            // required, no fallback: telemetry must not silently share the application queue connection.
            'connection' => env('SLOGGER_DISPATCHER_QUEUE_CONNECTION'),
            'name'       => env('SLOGGER_DISPATCHER_QUEUE_NAME', 'slogger'),
            // number of worker processes.
            'workers_num' => env('SLOGGER_DISPATCHER_QUEUE_WORKERS_COUNT', 3),

            'api_clients' => [
                'default' => env('SLOGGER_DISPATCHER_QUEUE_API_CLIENT', 'socket'),

                'socket' => [
                    // socket address for socket backend (e.g. tcp://host:port).
                    'url' => env('SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL'),
                ],
            ],
        ],
    ],

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

    // global data masking. it is applied by the dispatcher job, right before a batch
    // is sent - never in the traced application, which must not pay for masking a
    // payload. masking is off only when all three lists below are empty.
    'masking' => [
        // masks matched against a trace data key. the top level of a trace's data is
        // the watcher's own structure and is never matched; matching starts one level
        // in, where the traced data actually is.
        //
        // a value under a matching key is replaced whole - nothing of it survives.
        //
        // masks, not substrings: `authorization` matches only itself, `*token*`
        // matches `api_token` and `access_token`, `otp*` matches `otp_code` and not
        // `crypto`. Matching is against the key alone, case-insensitively; a match on
        // a key covers everything under it.
        'full_keys' => [
            'auth',
            'authorization',
            'proxy-authorization',
            'www-authenticate',
            '*token*',
            '*password*',
            '*passwd*',
            'passphrase',
            '*secret*',
            '*api_key*',
            '*apikey*',
            '*api-key*',
            '*credential*',
            '*cookie*',
            '*signature*',
            '*private_key*',
            'session',
            '*session_id*',
            'otp*',
            'cvv',
            'cvc',
            'iban',
            '*card_number*',
            'ssn',
            '*recovery_code*',
        ],

        // a value under a matching key keeps two characters at each end, so two
        // records still look different. these identify a person rather than
        // authenticate one - never put a secret here.
        'partial_keys' => [
            '*email*',
            '*phone*',
            '*recipient*',
            'username',
            'first_name',
            'last_name',
            'full_name',
            'middle_name',
            '*firstname*',
            '*lastname*',
            'surname',
        ],

        // matched against the value instead of the key, and masked in place, keeping
        // the rest of the string readable. some things identify a person or a secret
        // by their own shape wherever they turn up - an address inside a notifiable
        // string, a key inside an exception message - and no key name points at those.
        // an invalid pattern is ignored, not fatal.
        //
        // order matters: the first pattern to match a stretch of text wins, so the
        // narrow ones come before the broad one. a pattern with a capture group masks
        // the group and keeps the rest.
        'value_patterns' => [
            // credentials written into a url's authority: https://user:secret@host
            'url_credentials' => '/\/\/[^\/\s:@]+:([^\/\s]+)@/',

            // a secret written into a url, wherever that url turns up: a Location
            // header, an exception message, a log line
            'url_secret' => '/[?&][\w.-]*(?:token|key|secret|pass|auth|code|sig)[\w.-]*=([^&\s"\'<>]+)/i',

            'email' => '/[\w.+-]+@[\w-]+\.[\w.-]*[\w-]/u',
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

                    // stop recording a response body above this many bytes. capped by
                    // what the masker will read, so a larger value records nothing
                    // rather than something unmasked.
                    'max_content_length' => 1048576,
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
        ],
        [
            'class'   => NotificationWatcher::class,
            'enabled' => env('SLOGGER_LOG_NOTIFICATION_ENABLED', false),
        ],
        [
            'class'   => ScheduleWatcher::class,
            'enabled' => env('SLOGGER_LOG_SCHEDULE_ENABLED', false),
        ],
    ],
];
