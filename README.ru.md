# SLogger Laravel

[English](README.md) | **Русский**

SLogger Laravel — пакет трейсинга/наблюдаемости (observability) для Laravel-приложений. Он записывает трейсы запросов/команд/джобов/событий и т.д. и доставляет их на удалённый бэкенд через настраиваемые диспатчеры.

Этот README описывает установку, конфигурацию, вотчеры, маскирование, диспатчеры, профилирование и сценарии использования.

## Требования

- PHP >= 8.2
- Laravel 10+ (протестировано), должен работать на Laravel 12
- Драйвер очередей для диспатчера `queue`
- Опционально: расширение XHProf для профилирования

## Установка

1) Установите пакет (через Composer в вашем приложении):

```bash
composer require slogger/slogger-laravel
```

2) Опубликуйте конфиг:

```bash
php artisan vendor:publish --tag=slogger-laravel
```

3) Настройте env и `config/slogger.php` (см. ниже).

## Быстрый старт

Включите и используйте диспатчер `queue`:

```dotenv
SLOGGER_ENABLED=true
SLOGGER_TOKEN=your-api-token
SLOGGER_DISPATCHER=queue
SLOGGER_DISPATCHER_QUEUE_CONNECTION=redis
SLOGGER_DISPATCHER_QUEUE_NAME=slogger
SLOGGER_LOG_REQUESTS_ENABLED=true
```

Затем запустите воркеры диспатчера:

```bash
php artisan slogger:dispatcher:start
```

## Бэкенд сбора трейсов

SLogger Laravel отправляет трейсы в отдельный бэкенд-сервис. Референсный проект бэкенда:

```text
https://github.com/sprust/slogger
```

Используйте его инструкции по установке, чтобы развернуть сервер и настроить URL/токен API-клиента в этом пакете.

### Свой бэкенд / клиент

Бэкенд можно заменить, предоставив собственный API-клиент. Переопределите `ApiClientFactory::create` и верните свою реализацию `SLoggerLaravel\\Dispatcher\\ApiClients\\ApiClientInterface`, отправляющую трейсы на ваш бэкенд.

## Конфигурация

Вся конфигурация находится в `config/slogger.php` с переопределением через env. Ключевые секции:

### Общее

```dotenv
SLOGGER_ENABLED=false
SLOGGER_TOKEN=
SLOGGER_TRACE_ID_PREFIX=
SLOGGER_LOG_CHANNEL=daily
```

- `SLOGGER_ENABLED`: глобальное включение/выключение всего трейсинга.
- `SLOGGER_TOKEN`: API-токен для диспатчеров.
- `SLOGGER_TRACE_ID_PREFIX`: свой префикс для trace ID. Если пустой — используется slug от `app.name` или `app`.
- `SLOGGER_LOG_CHANNEL`: канал для внутренних ошибок пакета.

### Диспатчеры

```dotenv
SLOGGER_DISPATCHER=queue
SLOGGER_DISPATCHER_QUEUE_CONNECTION=slogger-rabbitmq
SLOGGER_DISPATCHER_QUEUE_NAME=slogger
SLOGGER_DISPATCHER_QUEUE_WORKERS_COUNT=3
SLOGGER_DISPATCHER_QUEUE_API_CLIENT=socket
SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL=tcp://0.0.0.0:0002
```

- `SLOGGER_DISPATCHER`: `queue` или `memory`.
- Диспатчер `queue` запускает воркер-процессы (по аналогии с Horizon) и отправляет трейсы через HTTP- или socket-клиент.
- Диспатчер `memory` хранит трейсы в памяти (удобно для тестов/разработки).

`SLOGGER_DISPATCHER_QUEUE_CONNECTION` **обязателен** для диспатчера `queue` — фолбэка на
`QUEUE_CONNECTION` нет намеренно: телеметрия не должна молча делить queue-коннекшен с
приложением. Используйте выделенный коннекшен.

Ретраи отправки зафиксированы намеренно: 5 попыток с паузами 1/10/30/60 секунд между ними.
После исчерпания попыток батч **дропается** с rate-limited предупреждением в лог-канале
SLogger — телеметрия никогда не засоряет хранилище `failed_jobs`.

### Профилирование

```dotenv
SLOGGER_PROFILING_ENABLED=true
```

Включает XHProf-профилирование для трейсов HTTP-клиента (см. раздел «Профилирование»).

### Заголовок родительского трейса запроса

```dotenv
SLOGGER_REQUESTS_HEADER_PARENT_TRACE_ID_KEY=x-parent-trace-id
```

Позволяет связывать дочерние трейсы с родительскими запросами через кастомный заголовок.

### Вотчеры (включение/выключение)

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

## Что записывает SLogger

Каждый трейс содержит:
- `trace_id`, `parent_trace_id`, `type`, `status`, `tags`
- `data` (данные конкретного вотчера)
- `duration`, `memory`, `cpu`, `logged_at`

Основные данные вотчеров:
- `request`: url, метод, action, заголовки/параметры, ответ (для JSON-ответов)
- `job`: коннекшен, payload, статус, исключение
- `event`: слушатели, broadcast, опционально сериализованный payload
- `model`: действие, класс модели, ключ, изменения
- `mail`: from/to/cc/bcc, тема, queued, mailable/notification
- `notification`: notifiable, канал, queued, ответ
- `cache`: тип, ключ, теги, значение
- `db`: запрос, биндинги, время
- `http-client`: метод, url, запрос/ответ
- `schedule`: команда, описание, cron, вывод
- `dump`, `log`, `gate`: информация о dump/сообщении/ability

## Запросы

### Middleware

Для трейсинга HTTP-запросов добавьте middleware к нужным роутам:

```php
\SLoggerLaravel\Middleware\HttpMiddleware::class
```

### Конфиг вотчера запросов

`config/slogger.php`:

```php
'watchers' => [
    [
        'class'   => \SLoggerLaravel\Watchers\Parents\RequestWatcher::class,
        'enabled' => env('SLOGGER_LOG_REQUESTS_ENABLED', false),
        'config'  => [
            // логировать только совпавшие пути (опционально)
            'only_paths' => [
                // 'api/*',
            ],

            // пропускать совпавшие пути
            'excepted_paths' => [
                // 'health',
            ],

            'input' => [
                // применять форматирование входных данных только для этих путей
                'only_paths' => [
                    // 'api/*',
                ],

                // скрывать все параметры запроса для этих путей
                'hidden_paths' => [
                    // 'auth/*',
                ],

                // маскирование заголовков по url_pattern
                'headers_masking' => [
                    '*' => ['authorization', 'cookie', 'x-xsrf-token'],
                ],

                // маскирование параметров по url_pattern
                'parameters_masking' => [
                    '*' => ['*token*', '*password*'],
                ],
            ],

            'output' => [
                // применять форматирование ответа только для этих путей
                'only_paths' => [
                    // 'api/*',
                ],

                // скрывать все данные ответа для этих путей
                'hidden_paths' => [
                    // 'auth/*',
                ],

                // маскирование заголовков ответа по url_pattern
                'headers_masking' => [
                    '*' => ['set-cookie'],
                ],

                // маскирование полей ответа по url_pattern
                'fields_masking' => [
                    '*' => ['*token*', '*password*'],
                ],

                // лимит размера json-ответа (в байтах)
                'max_content_length' => 1048576,
            ],
        ],
    ],
],
```

#### `only_paths`
- `only_paths` (верхний уровень): логировать только совпавшие пути запросов.
- `input.only_paths`: применять маскирование входных данных только к совпавшим путям (остальные вычищаются).
- `output.only_paths`: применять маскирование ответа только к совпавшим путям (остальные вычищаются).

Паттерны сопоставляются через Laravel `Str::is`.

### Размер JSON-ответа

Слишком большие JSON-ответы пропускаются и помечаются так:

```json
{"__skipped": "response_too_large"}
```

## Правила маскирования

Маскирование основано на паттернах и настраивается.

Замаскированные значения сохраняют базовые типы:
- `bool` -> `false`
- `int` -> `0`
- `float` -> `0.0`
- `string` -> замаскированная строка
- массивы/объекты -> замаскированная строка

## Трейсинг Guzzle / HTTP-клиента

Хендлер SLogger можно подключить к Guzzle:

```php
new \GuzzleHttp\Client([
    'base_uri' => 'https://url.com',
    'handler'  => app(\SLoggerLaravel\Guzzle\GuzzleHandlerFactory::class)->prepareHandler(
        (new \SLoggerLaravel\RequestPreparer\RequestDataFormatters())
            ->add(
                new \SLoggerLaravel\RequestPreparer\RequestDataFormatter(
                    urlPatterns: ['*'],
                    requestHeaders: ['authorization']
                )
            )
            ->add(
                new \SLoggerLaravel\RequestPreparer\RequestDataFormatter(
                    urlPatterns: ['/api/auth/*', '*sensitive/some/*'],
                    hideAllResponseData: true
                )
            )
    ),
])
```

## Диспатчеры

### Диспатчер queue

Запуск диспатчера (порождает воркеры очереди):

```bash
php artisan slogger:dispatcher:start
```

- Родительские трейсы отправляются немедленно.
- Дочерние трейсы батчатся (размер батча по умолчанию: 5).
- Осиротевшие (orphan) трейсы отправляются немедленно.
- При завершении процесса оставшиеся трейсы флашатся.

Остановка диспатчера:

```bash
php artisan slogger:dispatcher:stop
```

### Диспатчер memory

Хранит трейсы только в памяти. Предназначен для тестов/локальной разработки.

## Хранилище

SLogger не сохраняет трейсы локально. Единственный локальный файл — файл состояния диспатчера:

```text
storage/slogger/dispatcher-state-*.json
```

Папку имеет смысл добавить в ignore:

```gitignore
storage/slogger/*
```

## Профилирование (XHProf)

Только для трейсинга HTTP-клиента.

1) Установите расширение:

```bash
pecl install xhprof
```

2) Включите в `php.ini`:

```ini
[xhprof]
extension=xhprof.so
```

3) Включите:

```dotenv
SLOGGER_PROFILING_ENABLED=true
```

## Тестирование

Запуск тестов:

```bash
vendor/bin/phpunit
```

Конфигурация testbench использует in-memory sqlite и диспатчер `memory`.

## Диагностика проблем

- Диспатчер не стартует: проверьте `SLOGGER_ENABLED=true` и корректное имя диспатчера.
- Нет трейсов: убедитесь, что вотчеры включены, а middleware применён к запросам.
- Диспатчер queue не отправляет: проверьте воркеры очереди и URL API-клиента.
- Ошибки socket-клиента: проверьте адрес сокета и доступность бэкенда.

## Лицензия

MIT
