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
            // required, no fallback: telemetry must not share the application queue
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

    // applied by the dispatcher job, never in the traced application. off only when
    // all three lists below are empty.
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

                    // above this the parameters are not recorded at all. a value
                    // larger than the masker reads records nothing, not something raw
                    'max_content_length' => 1000000,
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

                    // the same for the response body
                    'max_content_length' => 1000000,
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
