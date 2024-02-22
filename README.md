
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
SLOGGER_ENABLED=true
SLOGGER_REQUESTS_HEADER_PARENT_TRACE_ID_KEY=x-parent-trace-id
SLOGGER_HTTP_CLIENT_URL=
SLOGGER_HTTP_CLIENT_TOKEN=

# slogger.profiling
SLOGGER_PROFILING_ENABLED=true

# slogger.watchers
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
```
