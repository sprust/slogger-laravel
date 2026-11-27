# Code Review: `feature/local-proxy` (vs `master`)

Ревью изменений в ветке `feature/local-proxy` относительно `master` (`269a159..c12a0f7`, 82 файла, ~12.3k строк).

В целом архитектура продумана лучше среднего (атомарный state, pinning inode в PHP-ридере, ограничение input-бюджета, строгий `golangci-lint`), но в коде есть реальные гонки с падением прокси, баги в edge-кейсах восстановления/shutdown/сети, серьёзная write-amplification и ряд неряшливых мест.

---

## Critical / High (баги, гонки, падения, ложные алерты)

### 1. Гонка `RecoverFile` ↔ `ReadRecord` (падение прокси) + ложный `LocalProxySendStalledEvent` после крэша
* `local-proxy/internal/storage/storage.go:57-100`, `342-353`, `516-551`
* `local-proxy/internal/sender/sender.go:462-473`

`NewStore()` не восстанавливает существующие файлы на старте, хотя процесс уже держит эксклюзивный `flock`. Вместо этого:
- `Sender.ensureFile()` вызывает `RecoverFile` **только если `IsSealed(name) == true`**, т.е. для файла **текущего часа** восстановление не запускается.
- `Store.openFile()` вызывает `RecoverFile()` (который делает `file.Truncate()`) лениво при первом `Append()` из горутины `Server.write` — **параллельно с `Sender`, который уже читает этот же файл без `Store.mu`**.

**К чему это приводит:**
1. **Падение прокси в гонке:** `ReadRecord()` делает `file.Stat()` (видит старый размер с битым хвостом, напр. 210), в этот момент `openFile() -> RecoverFile()` обрезает файл до 200, далее `file.ReadAt(header[:], 200)` возвращает `io.EOF` → `Sender.readAvailable()` возвращает фатальную ошибку → весь прокси падает.
2. **Ложный алерт о зависании до конца часа:** если прокси упал с оборванным хвостом (напр. 10 байт) в файле текущего часа, поднялся и нового трафика нет, `Sender` не обрезает хвост до смены часа. `readerOffset != stat.Size()`, `finishIfComplete()` не срабатывает, PHP-ридер считает `pending_bytes = 10` и через 5 минут стреляет ложный `LocalProxySendStalledEvent`.
3. **Завышенный `spoolBytes` на старте:** `loadSpoolFiles()` суммирует сырые размеры до обрезки.

**Фикс:** восстанавливать существующие файлы трейсов **один раз в `NewStore()`** до запуска `Server` и `Sender` (под эксклюзивным локом никого другого нет).

---

### 2. `Store.Append()` создаёт пустой файл часа **до** проверки `maxSpoolBytes`
* `local-proxy/internal/storage/storage.go:150-168`

`s.openFile(hour)` (`O_CREATE` + `syncDirectory`) вызывается раньше проверки `recordSize > s.maxSpoolBytes-spoolBytes`.
- При переполненном спуле на смене часа (или если `s.file == nil`) каждый отклонённый `Append` создаёт 0-байтовый файл и делает `fsync` директории.
- Если одиночный фрейм больше `maxSpoolBytes` при пустом спуле: `Append` создаёт пустой файл → возвращает `ErrSpoolFull` → `Sender` просыпается и через `CompleteFile` удаляет этот пустой файл с двумя `saveState` и двумя `syncDirectory` → следующий запрос снова его создаёт → бесконечный churn диска.

**Фикс:** проверять квоту `spoolBytes` до `openFile()`.

---

### 3. Поломанная семантика `idle` соединений + таймаут graceful shutdown (5s vs 20s)
* `local-proxy/internal/server/server.go:257-303`, `327-340`
* `local-proxy/cmd/slogger-local-proxy/main.go:70-86`

1. В `handleFrame()` соединение помечено как `idle = true` **на всё время `ReadFrameBudgeted()`** — флаг снимается только на строке 273, после прочтения всего фрейма (до 64 MiB).
   - Метрика `idle_connections` врёт (считает активно заливающий клиент idle-соединением).
   - При shutdown `closeIdleConnections()` обрывает соединения, которые прямо сейчас читают фрейм.
2. И наоборот: если клиент подвис на чтении ответа `"received"` (`localWriteTimeout = 20s`, `idle == false`), `closeIdleConnections()` его **не закрывает**, `s.handlers.Wait()` висит до 20 секунд, а `main.go` рубит процесс через **5 секунд** с `os.Exit(1)` — без `store.Close()`, `logger.Close()`, финальных метрик и удаления `local-proxy.sock`.

---

### 4. Отсутствие прозрачного реконнекта в `upstream` на протухший TCP-коннект
* `local-proxy/internal/sender/upstream.go:37-67`
* `local-proxy/internal/sender/sender.go:315-335`

В PHP-клиенте (`src/Dispatcher/ApiClients/Socket/SocketClient.php:118-126`) намеренно делается 1 прозрачный реконнект при закрытии idle-соединения сервером.
Go-прокси держит persistent TCP-сокет `u.conn` без такого ретрая: любой idle-таймаут на LB/NAT/приёмнике на следующем же батче:
- пишет ошибку в `stderr` и `local-proxy-errors-*.logs`,
- инкрементит `failed_send_attempts_total`,
- спит `RetryDelay` (3 секунды) перед переподключением.

На проде это превратится в постоянный спам в error-логи и 3-секундные задержки на каждом idle-разрыве.

---

### 5. `maxLocalConnections = 32` захардкожен, молча дропает коннекты без лога и без метрики
* `local-proxy/internal/server/server.go:22`, `162-173`

- PHP `ConnectionPool` держит постоянные соединения (1 на воркер `queue:work`/Horizon, до 8 на Swoole/Octane-воркер), а `localReadTimeout` держит их открытыми 5 минут.
- При >32 воркерах на сервере 33-й и далее получают мгновенный `conn.Close()` в `default:` — **ни строчки в лог (даже rate-limited), ни счётчика `rejected_connections_total` в метриках**.
- В связке с урезанным профилем ретраев прокси (`3 tries`, `[2, 5]s`) воркеры сверх лимита молча теряют трейсы, а на стороне прокси всё выглядит «зелёным».

---

### 6. `main.go` берёт `rootDir` из `os.Getwd()`, игнорируя директорию `-env` + нет проверки лимита Unix-сокета (107 байт)
* `local-proxy/cmd/slogger-local-proxy/main.go:36-43`
* `local-proxy/internal/config/config.go:84-88`
* `src/Configs/DispatcherQueueConfig.php:61-63`

1. Если в Supervisor/systemd указать `command=/var/www/app/vendor/bin/slogger-local-proxy -env /var/www/app/.env`, но забыть `directory=/var/www/app`, прокси молча создаст сокет и спул в `<cwd>/storage/slogger/traces` (например `/storage/slogger/traces`), пока Laravel будет стучаться в `/var/www/app/storage/slogger/traces`.
2. Ни Go, ни PHP не проверяют лимит `sockaddr_un.sun_path` в Linux (**108 байт с `\0`**, т.е. максимум 107 символов). На длинных путях релизов (`/var/www/releases/.../storage/slogger/traces/local-proxy.sock`) `bind` упадёт с невнятным `invalid argument`.

---

### 7. DoS / переаллокация памяти на handshake и ответах upstream + реальный расход RAM в 3–4× выше бюджета
* `local-proxy/internal/server/server.go:222`
* `local-proxy/internal/sender/upstream.go:88`, `118`
* `local-proxy/internal/protocol/frame.go:63-68`
* `local-proxy/internal/server/memory_budget.go:24-52`

1. **Auth-фрейм в `server.go:222`** принимает до `MaxFrameSize` (**64 MiB**), только чтобы его выкинуть (`_`). Достаточно лимита в несколько KiB.
2. **Ответы upstream в `upstream.go:88,118`** (`"ok"` и `"received"`) тоже читаются с лимитом **64 MiB** вместо условных 64 байт — при ошибке конфигурации порта любой мусор с длиной `< 64 MiB` аллоцирует десятки мегабайт.
3. **`ReadFrameBudgeted()`** аллоцирует `make([]byte, length)` целиком сразу после чтения 4-байтного заголовка. 4 подвисших клиента с заголовком `64 MiB` мгновенно выжрут все `256 MiB` бюджета и заморозят остальные 28 соединений на 5 минут (`localReadTimeout`).
4. **Отсутствие FIFO в `memoryBudget`:** поток мелких фреймов способен бесконечно голодать крупный фрейм в `Acquire()`.
5. **`ValidateRecord()`** во время удержания `payload` (64 MiB) делает `json.Unmarshal` в `envelope`, затем `json.Unmarshal` в строку `encodedMessages` (~ещё 64 MiB), плюс `json.Marshal` на каждую операцию — реальный пик RAM на 4 фрейма по 64 MiB уходит к **800 MiB – 1 GiB** вместо заявленных 256 MiB.

---

### 8. Баги в `LocalProxyMetricsCommand` и `LocalProxyMetricsReader`
* `src/Commands/LocalProxyMetricsCommand.php:71-90`, `119-121`, `166`
* `src/LocalProxy/LocalProxyMetricsReader.php:28-38`

1. **ANSI-мусор в пайпе (`clearScreen`):** в режиме `json` без `--once` команда безусловно шлёт `\033[2J\033[;H` в `stdout`, не проверяя TTY (`$this->output->isDecorated()` / `stream_isatty`). Любой `php artisan slogger:local-proxy:metrics json | jq` или сборщик логов ломается. При исключении в `catch` (строка 74) `clearScreen` вообще не вызывается.
2. **Забыт `clearstatcache(true, $path)` в `LocalProxyMetricsReader::read()`:** в `scanHourlyFileSizes()` и `LocalProxySenderStateReader` stat-кэш чистится явно (`// a poll is a repeated observation`), а в `read()` перед `is_file($path)` забыли. Плюс `is_file()` следует за симлинками и не защищает от FIFO/гигантских файлов (в отличие от `LocalProxySenderStateReader`).
3. **Нет обработки `SIGINT`/`SIGTERM`:** бесконечный `while (true)` + `usleep()`, без `SignalableCommandInterface` / `$this->trap()`. При остановке Supervisor может прибить процесс посреди вывода JSON или посреди выполнения listener'а алерта. А на 32-битном PHP `3600 * 1_000_000` переполняет `int` в `usleep()`.
4. **`printError()` в `line`-формате (строка 166):** экранируются только `"`, но не `\n`, `\r` и `;` — многострочный текст исключения ломает однострочный парсинг `key=value;...`.

---

## Medium (производительность, несовместимость, дыры в парсере/валидации)

### 9. Жёсткая write-amplification и блокировка `Store.mu` при удалении активного файла на каждом батче
* `local-proxy/internal/storage/storage.go:260-299`
* `local-proxy/internal/sender/sender.go:163-172`, `276-297`, `341`

1. `CompleteFile()` удаляет файл **текущего часа** каждый раз, когда `Sender` догоняет EOF. На умеренном трафике (1 батч раз в >1с) **каждый отдельный батч** делает:
   - `Append`: `Stat` + `RecoverFile` + `OpenFile(O_CREATE)` + **`syncDirectory`** + `Write` + **`file.Sync`**;
   - `flush`: `CreateTemp` + `Write` + **`Sync`** + `Rename` + **`syncDirectory`**;
   - `CompleteFile` (**удерживая `Store.mu` и блокируя все входящие `Append`!**): `Close` + `saveState(completed=true)` (**`Sync` + `syncDirectory`**) + `Remove` + `saveState(empty)` (**`Sync` + `syncDirectory`**).
   Итого: **4 `fsync` файла + 4 `fsync` директории + create + unlink на каждый батч**. На облачных дисках (EBS/PD) это убивает p99 latency входящих воркеров.
2. **Лишние `saveOffset` внутри `readAvailable()`:** если одна запись содержит >200 операций (несколько батчей), `flush()` на каждом промежуточном батче вызывает `saveOffset(s.currentBatch.offset)`, где `offset == s.durableOffset` (офсет вообще не изменился!). Это делает бесполезный `CreateTemp + Sync + Rename + syncDirectory` и **меняет inode `local-proxy-state.json`**, из-за чего `LocalProxyMetricsReader` получает промахи и возвращает `pending_bytes = null`.

---

### 10. Локальная таймзона в именах часовых файлов и логов (DST + расхождение TZ контейнеров)
* `local-proxy/internal/storage/storage.go:413-425`
* `local-proxy/internal/sender/sender.go:580-596`
* `local-proxy/internal/logging/logger.go:57-94`
* `tests/Feature/LocalProxy/LocalProxyIntegrationTest.php:128`

- `CurrentFileName(now)` использует локальную TZ, `ValidTraceFileName()` парсит через `time.Parse` (UTC), а `cleanup()` через `time.ParseInLocation` (local).
- При переводе часов назад (DST fall-back) повторяющийся час в `ParseInLocation` неоднозначен, расчёт retention плавает на час.
- При разной `TZ` у Go-процесса и PHP/хоста расходятся даты файлов логов (в т.ч. `LocalProxyIntegrationTest.php:128` проверяет файл через PHP `date('Y_m_d')` — потенциальный флейк на границе полуночи).
- Всё внутреннее хранение (файлы часов, логов) должно использовать **UTC** (`now.UTC()`).

---

### 11. Баги в самописном 287-строчном `.env`-препроцессоре и валидации URL
* `local-proxy/internal/config/config.go:104-141`, `246`, `427-447`

1. **Проглоченная причина ошибки в `readEnv()` (строки 117, 128):** `os.ReadFile(envPath)` возвращает `fmt.Errorf("read env file %q", envPath)` без `%w` — оператор не отличит `ENOENT` от `EACCES` или синтаксической ошибки (если боялись утечки секретов из ошибки `godotenv.Unmarshal`, то `os.ReadFile` секретов не содержит, а для `Unmarshal` нужно писать хотя бы `"parse env file %q"`).
2. **`#` без пробела в unquoted-значении (строка 246):** `case char == '#': s.inComment = true` режет любое значение с `#` (например `SLOGGER_TOKEN=abc#123${SUFFIX}`), тогда как в Laravel (`vlucas/phpdotenv` v5) `#` начинает inline-комментарий только после пробела.
3. **`export\tKEY=val` (строка 106):** `strings.TrimPrefix(key, "export ")` не понимает таб после `export`.
4. **`validateUpstreamURL()` (строка 433):** не проверяет `parsed.User != nil` и `parsed.Fragment != ""` — `tcp://user:pass@127.0.0.1:9002#frag` проходит валидацию и молча отбрасывает креденшалы и фрагмент.

---

### 12. 7 проходов JSON-парсинга/маршалинга на каждую запись + HTML-escaping мутация
* `local-proxy/internal/tracepayload/record.go:62-148`
* `local-proxy/internal/sender/batch.go:70-105`

- Каждый payload проходит: `ValidateRecord` (`Unmarshal` envelope → `Unmarshal` string → `Decode` каждого сообщения → `json.Marshal(msg)` → `json.Marshal(string(raw))`), результат выкидывается, затем `Sender.readAvailable` делает **всё то же самое повторно** через `WalkOperations`, а затем `batch.encode()` ещё дважды маршалит массив и обёртку.
- `json.Marshal` по умолчанию экранирует `<`, `>`, `&` в `\u003c`, `\u003e`, `\u0026` (в отличие от PHP `json_encode` в direct-режиме), раздувая размер SQL-запросов и HTML-пейлоадов в 5× на каждый такой символ (и потом ещё двойное экранирование `\\u003c`). Использование `json.Encoder` с `SetEscapeHTML(false)` и подсчёт/валидация без лишних переаллокаций убрали бы и раздувание, и лишний CPU.

---

### 13. BC-break в минорной версии `2.2.0 → 2.3.0`: `SendTracesJob::__construct`
* `src/Dispatcher/Items/Queue/Jobs/SendTracesJob.php:56`

Сигнатура изменена с `__construct(TracesObject $traces)` на `__construct(TracesObject $traces, int $tries, array $backoff)` **без дефолтных значений**. Любой внешний код/тест, создающий `new SendTracesJob($traces)`, сломается с `ArgumentCountError` при обновлении с 2.2 на 2.3. Достаточно задать дефолты (`int $tries = 5, array $backoff = [5, 10, 30, 60]`).

---

### 14. `DownloadLocalProxyCommand`: ловушка `tempnam()` и edge-кейсы
* `src/Commands/DownloadLocalProxyCommand.php:40-71`, `107-127`

1. **`tempnam($directory, ...)` молча падает в `/tmp`**, если `$directory` недоступна на запись: команда сначала полностью скачивает бинарь в `/tmp`, проверяет SHA-256, и только потом падает на `rename()` (с `EXDEV`, если `/tmp` на tmpfs, или с `EACCES`). Нужно проверять `dirname($createdTemporaryPath) === realpath($directory)`.
2. **Если `--path` указывает на существующую директорию**, `is_file($destination)` вернёт `false`, бинарь скачается и упадёт с PHP warning на `rename()`.
3. **`ltrim($version, 'v')`** (строка 120) съедает любое число `v` (`vvv2.3.0` → `2.3.0`); регэксп разрешает `+` (`2.3.0+build`), но URL не кодируется; при `dev-*` версии от Composer сообщение об ошибке не говорит, какая версия нашлась и что можно передать `--release=`.
4. В fallback `json_decode((string) file_get_contents(...), true)` (строка 115) нет проверки `is_array($composer)`.

---

### 15. Дыры и несогласованность валидации метрик и Carbon 2 vs 3 на PHP-стороне
* `src/LocalProxy/Objects/LocalProxyMetricsSchema.php:37-62`
* `src/LocalProxy/Objects/LocalProxyMetricsObject.php:76-79`
* `src/LocalProxy/Objects/LocalProxyStorageMetricsObject.php:26-35`
* `src/LocalProxy/Objects/LocalProxyStorageStateObject.php:19-26`
* `src/LocalProxy/LocalProxySenderStateReader.php:95-109`
* `src/LocalProxy/LocalProxyAlertChecker.php:111`

1. **Отрицательные значения проходят валидацию в 20 из 22 полей:** в `LocalProxyMetricsSchema` есть `nonNegativeInt()`, но он вызван ровно **два раза** (`spool_limit_bytes` и `storage.state.offset`). Все остальные 20 счётчиков и gauge'ей (`goroutines`, `heap_alloc_bytes`, `rss_bytes`, `connections`, `hourly_files`, `hourly_bytes`, `sent_operations_total` и т.д.) валидируются через `int()` / `optionalInt()`, спокойно принимая `-100`.
2. **Не валидируются имена файлов в `LocalProxyStorageMetricsObject` и `LocalProxyStorageStateObject`:** в отличие от `LocalProxySenderStateReader`, поля `oldest_hourly_file`, `newest_hourly_file`, `storage.state.file` принимают любую строку (включая `;` и `\n`, ломая формат `line`).
3. **Разное поведение `isStale()` в Carbon 2 (Laravel 10) vs Carbon 3 (Laravel 11/12):** `diffInSeconds()` в Carbon 2 возвращает усечённый `int` (`10.9` → `10`, не stale), а в Carbon 3 — `float` (`10.9 > 10`, stale). Надёжнее сравнивать `abs(Carbon::now()->getTimestamp() - ...)` или `floatDiffInSeconds`.
4. **Усечение субсекунд в `LocalProxyAlertChecker` (строка 111):** `$metrics->startedAt->toIso8601String()` отбрасывает микросекунды — быстрый рестарт в пределах той же секунды не отличится по `startedAt`.
5. **Рассинхрон state-валидации Go и PHP:** Go `StateStore.Load()` принимает `{"version":1}` без `offset` (считает `0`), а `LocalProxySenderStateReader::decode()` его отвергает; при этом **оба** принимают бессмысленный `{"version":1,"file":"","offset":500,"completed":false}` (ненулевой offset при пустом файле).
6. **`PENDING_READ_ATTEMPTS = 2`** (`src/LocalProxy/LocalProxyMetricsReader.php:13`): при высокой интенсивности отправки (особенно с учётом лишних смен inode из п. 9) 2 попыток маловато — `pending_bytes` будет периодически проваливаться в `null`, глуша алерты.

---

## Code Smells / Bad Practices / Мелкие дефекты

- `local-proxy/internal/sender/sender.go:556`: `err.Error() != errRetryFileExpired.Error()` — сравнение ошибок по строке. Вместо возврата sentinel-ошибки внутри `errors.Join` достаточно возвращать `(expired bool, err error)` из `cleanup()`.
- `local-proxy/internal/logging/logger.go:51-69`: на каждый `Error()` заново аллоцируется `slog.New(slog.NewJSONHandler(...))` вместо кэширования при `openFile()`. Вызов `Error()` после `Close()` молча переоткрывает файл и течёт дескриптором; `Cleanup()` может удалить файл, который всё ещё открыт в `l.file` (если 3+ дня не было ошибок); `JSONHandler` пишет `time.Now()` вместо инжектированного `l.now()`.
- `local-proxy/internal/metrics/collector.go:232-254`: `collectFileMetrics()` вызывает `entry.Info()` (`lstat`) на **каждый** файл в директории (включая `local-proxy.lock`, state, metrics, временные файлы), и только потом проверяет имя файла. Проверка имени должна идти до `entry.Info()`.
- `local-proxy/internal/sender/sender.go:193,205,255,267`: половина `Sender` использует инжектированные часы `s.now()`, а таймеры батча — глобальные `time.Now()` / `time.Since()`.
- `local-proxy/internal/sender/upstream.go:50-58` и `local-proxy/internal/server/server.go:129-137`: в модуле на **Go 1.26** на каждый `send()` спавнится отдельная горутина-watcher для закрытия сокета по `ctx.Done()`, хотя со времён Go 1.21 есть `stop := context.AfterFunc(ctx, func() { _ = conn.Close() }); defer stop()` без горутины на happy path.
- `local-proxy/internal/sender/batch.go:50-51`: `size, _ := operation.EncodedSize()` молча проглатывает ошибку.
- `local-proxy/internal/storage/storage.go:57-68`: `NewStore(dir, now, limits ...int64)` — вариадик вместо явного аргумента/конфига с рантайм-проверкой `if len(limits) > 1`.
- `local-proxy/internal/storage/storage.go:287`: пропущен `Version: StateVersion` (держится на мутации внутри `StateStore.Save`), хотя на строке 293 он указан явно.
- `local-proxy/internal/server/server.go:118` vs `README.md:1223`: между `Listen` и `Chmod(0o600)` есть короткое TOCTOU-окно (если директория создана заранее с `0755`), а в troubleshooting написано про доступность сокета обоим юзерам, хотя код жёстко ставит `0o600`/`0o700` (под разными юзерами даже одной группы не заработает — лучше явно писать везде, что юзер обязан быть один).
- `local-proxy/scripts/check.sh:18-30`: `git grep` сломается вне git-репозитория (например при сборке из tarball), а скрипт оставляет на диске артефакт `../build/slogger-local-proxy-check`.
- Дублирование логики `isStale`: проверяется и в `src/Commands/LocalProxyMetricsCommand.php:135`, и в `src/LocalProxy/LocalProxyAlertChecker.php:37`.
- Нет `last_sent_at` в метриках Go-прокси: из-за этого `LocalProxySendStalledEvent` не работает в режиме `--once` и теряет историю простоя при перезапуске команды мониторинга (отсчитывает 5 минут с нуля).

---

## Топ-5 что править в первую очередь перед мержем

1. Перенести `RecoverFile` всех существующих файлов в `NewStore()` (убирает гонку с крэшем, ложный stall-алерт и кривой `spoolBytes`).
2. Проверять `maxSpoolBytes` в `Store.Append()` **до** `openFile()`.
3. Починить `idle`-трекинг + таймауты shutdown в `server.go` / `main.go`, ограничить размер auth/upstream-ответов и сделать прозрачный 1-разовый реконнект в `upstream.go`.
4. Убрать удаление активного файла часа (или не дёргать `saveOffset`, когда `offset == durableOffset`) + перевести имена часовых/лог-файлов на UTC.
5. Починить `clearScreen` для non-TTY, добавить `clearstatcache()` в `LocalProxyMetricsReader::read()`, вернуть дефолты в `SendTracesJob::__construct()`, и закрыть `nonNegativeInt()` + `tempnam()` в PHP-части.
