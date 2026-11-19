# Метрики local-proxy: artisan-команда и алертинг

## Цель

Дать эксплуатации способ смотреть метрики Go-прокси из приложения:

1. Artisan-команда, выводящая метрики в терминал в двух форматах (`line`, `json`),
   с настраиваемой частотой опроса (по умолчанию 1 секунда).
2. Отдельный переиспользуемый сервис чтения метрик (не в классе команды).
3. Алертинг: при превышении порогов (накопилось N трейсов / M минут без успешной
   отправки) выбрасывается событие, слушатели которого настраиваются в существующей
   секции `listeners` конфига.

## Источник данных

Прокси раз в секунду атомарно (tmp + rename, права 0600) пишет
`<traces_path>/local-proxy-metrics.json` (см. `local-proxy/internal/metrics/collector.go`,
константа `FileName`). Структура снимка:

```json
{
  "collected_at": "2026-11-19T10:00:01.123456789+03:00",   // RFC3339Nano
  "uptime_seconds": 3600.5,
  "process": { "goroutines": 21, "heap_alloc_bytes": 0, "heap_sys_bytes": 0,
               "stack_inuse_bytes": 0, "rss_bytes": 0 },
  "server":  { "connections": 0, "idle_connections": 0, "connection_slots_used": 0,
               "connection_limit": 32, "queued_records": 0, "queue_capacity_records": 0,
               "input_budget_used_bytes": 0, "input_budget_total_bytes": 0 },
  "storage": { "hourly_files": 2, "hourly_bytes": 12345, "error_log_files": 1,
               "appended_records_total": 100, "appended_bytes_total": 999,
               "oldest_hourly_file": "2026_11_19_09", "newest_hourly_file": "2026_11_19_10",
               "state": { "file": "2026_11_19_09", "offset": 5000, "completed": false } },
  "sender":  { "sent_batches_total": 50, "sent_operations_total": 98,
               "sent_bytes_total": 900, "failed_send_attempts_total": 2 }
}
```

Важные свойства источника:

- Файл обновляется раз в секунду работающим прокси. Протухание определяется по
  `collected_at` (README уже рекомендует судить о свежести по нему).
- Счётчики `*_total` — за время жизни процесса прокси и сбрасываются при его
  перезапуске.
- Запись атомарна, частично прочитанного файла не бывает.
- Файл может отсутствовать (прокси не запущен / ни разу не записал).
- Путь к директории — `slogger.local_proxy.traces_path`, нормализация уже реализована в
  `DispatcherQueueConfig::getLocalProxyTracesPath()` (`src/Configs/DispatcherQueueConfig.php:66`).

## Структура классов

Новый неймспейс `src/LocalProxy/` (сейчас прокси-код размазан: команда скачивания в
`src/Commands/`, путь в `DispatcherQueueConfig`). Команда скачивания остаётся на месте,
переносить её не будем, чтобы не ломать сигнатуру.

### 1. `src/LocalProxy/Objects/LocalProxyMetricsObject.php`

DTO снимка. `readonly`, конструируется из декодированного массива:

```php
LocalProxyMetricsObject::fromArray(array $data): self
```

Поля — вложенные readonly-объекты (`ProcessMetricsObject`, `ServerMetricsObject`,
`StorageMetricsObject`, `SenderMetricsObject`, `StateMetricsObject`), по структуре
снимка выше. `collected_at` — `Carbon`. Плюс производные методы:

- `isStale(int $maxAgeSeconds): bool` — свежесть по `collected_at`;
- `getPendingRecords(): int` — `max(0, appended_records_total - sent_operations_total)`;
- `toArray(): array` — плоское представление `key.subkey=value` для формата `line`.

Поля, которых нет в файле (`omitempty` в Go: `rss_bytes`, `oldest_hourly_file`,
`newest_hourly_file`, `state.file`), — nullable с дефолтами.

### 2. `src/LocalProxy/LocalProxyMetricsReader.php`

Переиспользуемый сервис (запрос пользователя — «не пиши всё в классе команды»).
Зависит только от нового `LocalProxyConfig` (см. ниже). API:

```php
// null — файла нет или он не читается; исключений наружу нет
public function read(): ?LocalProxyMetricsObject;

// путь до metrics-файла (base_path(traces_path) . '/local-proxy-metrics.json')
public function getMetricsFilePath(): string;
```

Поведение при ошибках: файл отсутствует / невалидный JSON / нет `collected_at` →
`null` (команда покажет ошибку чтения сама; вызывающий код не обязан ловить
исключения в цикле опроса). Невалидный JSON трактуем как «файл недоступен», потому
что атомарная запись исключает частичный файл, а значит это либо битый файл, либо
чужой формат — в обоих случаях читать нечего.

Сервис ничего не кеширует: каждый `read()` читает файл заново (файл маленький,
частота — секунды).

### 3. `src/Configs/LocalProxyConfig.php`

Новый конфиг-класс (по образцу `GeneralConfig`/`DispatcherQueueConfig`):

```php
public function getTracesPath(): string;          // делегирует нормализацию
                                                  // DispatcherQueueConfig::getLocalProxyTracesPath()
public function getMetricsMaxSnapshotAgeSeconds(): int;  // default 10
public function isAlertingEnabled(): bool;               // default false
public function getAlertMaxPendingRecords(): int;        // default 10000
public function getAlertMaxMinutesWithoutSend(): int;    // default 5
```

Конфиг (`config/slogger.php`), секция `local_proxy` дополняется:

```php
'local_proxy' => [
    'traces_path' => env('SLOGGER_LOCAL_PROXY_TRACES_PATH', 'storage/slogger/traces'),
    'retention_hours' => env('SLOGGER_LOCAL_PROXY_RETENTION_HOURS', 24),

    'metrics' => [
        // снимок старше этого возраста считается протухшим (прокси мёртв или не пишет)
        'max_snapshot_age_seconds' => env('SLOGGER_LOCAL_PROXY_METRICS_MAX_SNAPSHOT_AGE', 10),

        'alerting' => [
            'enabled' => env('SLOGGER_LOCAL_PROXY_ALERTING_ENABLED', false),
            // накопилось больше этого числа неотправленных записей
            'max_pending_records' => env('SLOGGER_LOCAL_PROXY_ALERT_MAX_PENDING_RECORDS', 10000),
            // столько минут sent_operations_total не растёт
            'max_minutes_without_send' => env('SLOGGER_LOCAL_PROXY_ALERT_MAX_MINUTES_WITHOUT_SEND', 5),
        ],
    ],
],
```

### 4. События (`src/Events/`)

Два события по двум условиям пользователя:

```php
class LocalProxyBacklogExceededEvent          // накопилось трейсов
{
    public function __construct(
        public readonly int $pendingRecords,
        public readonly int $threshold,
        public readonly LocalProxyMetricsObject $metrics,
    ) {}
}

class LocalProxySendStalledEvent              // нет отправки N минут
{
    public function __construct(
        public readonly int $minutesWithoutSend,
        public readonly int $threshold,
        public readonly LocalProxyMetricsObject $metrics,
    ) {}
}
```

Слушателей в коробке нет — пользователь вешает свои через существующую секцию
`listeners` (она уже регистрируется в `ServiceProvider::registerListeners()`,
`src/ServiceProvider.php:119`):

```php
'listeners' => [
    WatcherErrorEvent::class => [WatcherErrorListener::class],

    // пример для пользователя (в конфиге пакета — закомментировано):
    // LocalProxyBacklogExceededEvent::class => [\App\Listeners\NotifyOps::class],
    // LocalProxySendStalledEvent::class    => [\App\Listeners\NotifyOps::class],
],
```

Нюанс, который надо задокументировать: `registerListeners()` работает только при
`SLOGGER_ENABLED=true`. Команда метрик доступна и при выключенном slogger (как
`slogger:local-proxy:download`), но тогда слушатели из конфига не зарегистрированы и
события алертинга ни до кого не дойдут. Это соответствует духу настройки (алертинг —
часть включённого пакета), пишем об этом в README.

### 5. `src/LocalProxy/LocalProxyAlertChecker.php`

Stateful-сервис (singleton), вызываемый командой на каждом тике:

```php
public function check(LocalProxyMetricsObject $metrics): void;
```

Логика:

- `enabled=false` → ничего не делает.
- **Backlog**: `pending > max_pending_records` → диспатч
  `LocalProxyBacklogExceededEvent`. Дедупликация: событие кидается один раз при
  пересечении порога; повторно — только после того, как значение вернулось под порог
  (re-arm), иначе каждую секунду будет по событию.
- **Send stalled**: сервис хранит `lastSentOperations` и `lastProgressAt`.
  - `sent_operations_total` вырос → обновить `lastProgressAt`.
  - `sent_operations_total` уменьшился → прокси перезапустился (счётчики за
    lifetime процесса): принять новое значение за базу, таймер сбросить.
  - `now - lastProgressAt > max_minutes_without_send` → диспатч
    `LocalProxySendStalledEvent`, с той же схемой дедупликации (re-arm при
    возобновлении прогресса).
  - Стартовое значение `lastProgressAt` — момент первой проверки: запуск команды на
    давно молчащем прокси не должен мгновенно стрелять (протухание снимка отдельно
    ловится через `isStale`, см. вывод команды).
- Протухший снимок в `check()` не обрабатываем как send-stalled: `sent` не растёт
  просто потому, что прокси мёртв. Отдельного события для протухания не добавляем
  (это видно в выводе команды и покрывается send-stalled, когда снимок снова
  появится); если в реализации покажется нужным — выносим в открытые вопросы.

### 6. `src/Commands/LocalProxyMetricsCommand.php`

```php
protected $signature = 'slogger:local-proxy:metrics
    {format=line : Output format: line or json}
    {--interval=1 : Seconds between polls (>= 0.1)}
    {--once : Print a single snapshot and exit}';
```

- `format` — первый аргумент, по умолчанию `line`. Невалидное значение →
  ошибка + `FAILURE`.
- `--interval` — частота опроса/вывода, по умолчанию 1 секунда. Дробные значения
  допустимы (`usleep`), нижняя граница 0.1 с, валидация с понятной ошибкой.
- `--once` — один снимок и выход. Нужен и для cron-сценариев, и для тестов
  (бесконечный цикл в тестах не гоняем). При недоступности метрик в `--once`
  возвращаем `FAILURE` — это делает команду пригодной для проверок живости.
- Цикл: `do { ... usleep(interval) } while (true)`. Выход — Ctrl+C (pcntl пакету
  больше не требуется, обработчик сигналов не добавляем).

Форматы вывода (делаются через `LocalProxyMetricsObject::toArray()` и `json_encode`,
не вручную):

- **line** — одна строка за тик, строки идут одна за другой (поток, удобно для
  `tail`/grep):

  ```
  collected_at=2026-11-19T10:00:01+03:00;uptime_seconds=3600;process.goroutines=21;server.connections=2;server.queued_records=0;storage.hourly_files=2;storage.hourly_bytes=12345;storage.appended_records_total=100;sender.sent_operations_total=98;sender.failed_send_attempts_total=2;pending_records=2
  ```

  Разделитель `;`, ключи плоские через точку. В строку добавляем производное
  `pending_records`.

- **json** — красивый вывод (`JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`) с полной
  очисткой терминала перед каждым тиком (`\033[2J\033[;H`), поверх — заголовок с
  текущим временем и подсказкой про Ctrl+C. В JSON включаем снимок как есть плюс
  `pending_records`.

Ошибки чтения (нет файла / протух / битый JSON): в `line` выводим
`error="metrics unavailable: <причина>"`, в `json` — объект с ключом `error`.
Протухание (`isStale`) подсвечиваем отдельно от отсутствия файла, причину видно в
строке/объекте.

На каждом тике после чтения метрик вызывается `LocalProxyAlertChecker::check()`.

### 7. Регистрация

`ServiceProvider::registerConsole()` (`src/ServiceProvider.php:161`): добавить
`LocalProxyMetricsCommand::class` рядом с `DownloadLocalProxyCommand` — безусловно,
команда-инструмент и не зависит от `SLOGGER_ENABLED` (как download).

`LocalProxyConfig`, `LocalProxyMetricsReader` — обычные классы, резолвятся контейнером,
явный биндинг не нужен. `LocalProxyAlertChecker` — `$this->app->singleton()` (состояние
дедупликации живёт в процессе команды).

## Тесты

`tests/Feature/LocalProxy/Metrics/` (фикстуры — готовые JSON-файлы, подкладываемые во
временный `traces_path` через `config(['slogger.local_proxy.traces_path' => ...])`):

- **Reader**: валидный файл → объект с верными полями; файл отсутствует → `null`;
  битый JSON → `null`; отсутствуют omitempty-поля → дефолты; `isStale` по
  `Carbon::setTestNow`.
- **Команда** (`--once`):
  - `line`: формат строки `k=v;k=v`, наличие `pending_records`;
  - `json`: валидный pretty-JSON со всеми секциями;
  - невалидный `format` → `FAILURE`;
  - невалидный `--interval` (0, отрицательный, нечисло) → `FAILURE`;
  - отсутствующий файл → `FAILURE` в `--once`, строка `error=...` в `line`.
- **AlertChecker**:
  - порог backlog пересечён → событие один раз; ниже порога → re-arm; снова выше →
    снова событие (`Event::fake()`);
  - `sent` не растёт N минут (`Carbon::setTestNow`) → `LocalProxySendStalledEvent`
    один раз; прогресс → re-arm;
  - сброс счётчика (рестарт прокси) не даёт ложного срабатывания;
  - `enabled=false` → событий нет.
- **Config**: дефолты и env-override новых ключей (по образцу
  `tests/Feature/Configs/DispatcherQueueConfigTest.php`).

Синхронизировать `workbench/config/slogger.php` (testbench читает конфиг оттуда).

## Документация

- `README.md` и `README.ru.md`: подраздел «Metrics command» внутри «Local proxy» —
  сигнатура команды, форматы, `--interval`, `--once`, алертинг (пороги, события,
  пример подключения слушателя через `listeners`), замечание про `SLOGGER_ENABLED`.
- `config/slogger.php` — новые ключи с комментариями (стиль секции сохранить:
  строчные комментарии, как у соседних).

## Порядок реализации

1. `LocalProxyConfig` + ключи в `config/slogger.php` и `workbench/config/slogger.php`.
2. `LocalProxyMetricsObject` (+ вложенные объекты, `toArray`, `getPendingRecords`,
   `isStale`).
3. `LocalProxyMetricsReader`.
4. События + `LocalProxyAlertChecker`.
5. `LocalProxyMetricsCommand` + регистрация в `ServiceProvider`.
6. Тесты.
7. README en/ru.

## Проверка

`make test`, `make check` (cs-fixer + phpstan + phpunit) — в docker-контейнере, как
принято в проекте. Go-часть не трогаем, `proxy-check` не нужен.

## Открытые вопросы (решено после первого раунда ревью)

1. **Метрика backlog.** Первоначальный `pending_records = appended_records_total -
   sent_operations_total` отклонён на ревью: record (входной кадр) и operation — разные
   единицы, формула неверна даже в одном процессе. Итог: `pending_bytes`, вычисляемый
   reader'ом из durable state — позиция отправителя читается из `local-proxy-state.json`
   (Go-совместимая валидация version/offset/file/completed; отсутствующий state = пустое
   состояние, как в Go `Load`; ENOENT отличается от EACCES листингом каталога), против
   размеров hourly-файлов на диске. Учитываются только regular-файлы (lstat с точной маской
   S_IFMT 0170000, symlink не разыменовывается — как `DirEntry.Type().IsRegular()` в Go).
   Консистентность наблюдения — по идентичности state-файла (dev+inode из fstat открытого
   дескриптора) до и после скана, причём первый дескриптор удерживается открытым до второго
   сравнения: прокси сохраняет state атомарным rename, а закреплённый открытым fd inode не
   может быть освобождён и переиспользован двумя записями между чтениями (empty→completed→
   empty с recycle inode на ext воспроизводился на ревью). Изменившийся state — один retry,
   затем `pending_bytes = null` (backlog неизвестен, алерт не перевзводится). Занижение при
   append посреди чтения; единичное завышение возможно при удалении файла retention посреди
   скана без смены state — следующий опрос самокорректируется. Гарантии — пока в каталог
   пишет только прокси. Порог: `max_pending_bytes`
   (env `SLOGGER_LOCAL_PROXY_ALERT_MAX_PENDING_BYTES`, дефолт 100 МиБ).
2. **Протухший снимок**: отдельного события нет. `isStale` проверяет абсолютный возраст —
   снимок из заметного будущего тоже протухший. `LocalProxyAlertChecker::check()` сам
   пропускает протухшие снимки (не полагается на CLI).
3. **`--once`** — оставлен: cron-проверки и тестируемость.
4. **Рестарт прокси** в send-stalled: детектируется по откату `uptime_seconds` (покрывает
   рестарт с тем же значением счётчика отправок), плюс по падению самого счётчика.

Валидация снимка строгая по всему wire schema (`LocalProxyMetricsSchema`): обязательные
блоки и все поля с типами, omitempty-поля nullable, но при наличии — строгого типа;
`collected_at` — RFC3339(Nano) с проверкой календарных и часовых компонентов
(`2026-02-30` отклоняется, Carbon нормализовал бы в 2 марта); `uptime_seconds` — конечное
неотрицательное (JSON `1e309` → INF отклоняется, иначе line-формат читал бы снимок, а
json падал бы на сериализации). `--interval`: конечное число от 0.1 до 3600. Невалидный
`traces_path` команда показывает как штатную ошибку, а не исключение. Дедуп-флаги алертов
ставятся только после успешного синхронного dispatch; исключение слушателя уходит в
`report()` (не в вывод метрик), и следующий тик повторяет попытку доставки.
