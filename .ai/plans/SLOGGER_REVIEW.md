# Жёсткое ревью проекта slogger/laravel

**Дата:** 2026-07-23. Метод: 4 параллельных ревью-агента по зонам (диспатчер-контур; вотчеры/процессор; объекты/хелперы/маскирование; аудит покрытия) + ручная верификация каждой критичной находки по коду. Статус: все «критические» и «высокие» подтверждены построчно, если не указано иное.

---

## 🔴 Критические

> **Статус 2026-07-23: K1–K5 исправлены** отдельными коммитами в `bugfix/horizon-jobs-leaking` (без пуша). Проверка: полный suite 138 тестов OK, phpstan level 8 — 0 ошибок, cs-fixer чист (контейнер `sll-php`, PHP 8.2).

### K1. ✅ `duration` теряется у всех create-трейсов: несовпадение ключей сериализации
**Исправлено** в `15012e2`: `fromJson` читает `'dur'`; регрессионный тест `testToJsonAndFromJsonKeepScalarFields` (round-trip всех скалярных полей).
`src/Objects/TraceCreateObject.php:42` пишет `'dur' => $this->duration`, а `fromJson` (строка 74) читает `isset($jsonData['du'])` — ключ, который никогда не пишется. После каждого прохода через очередь `duration === null`. `TraceUpdateObject` использует `'du'` с обеих сторон — потому update-путь работает, а create — нет. **Все метрики длительности create-трейсов молча теряются в проде.**

### K2. ✅ `Connection::write()`: `fwrite() === 0` не обрабатывается → вечный цикл на 100% CPU
**Исправлено** в `b0e3607`: `0` трактуется как «нет прогресса» и попадает в ветку таймаута/usleep; тест с заполненным send-буфером и нечитающим пиром ждёт `RuntimeException` по таймауту.
`src/Dispatcher/ApiClients/Socket/Connection.php:146-166`. Сокет неблокирующий (строка 78). При заполненном send-буфере `fwrite` возвращает `0`, а не `false`. Ветка таймаута/usleep срабатывает только на `=== false`; при `0` цикл делает `$timeout = null; $sentBytes += 0;` и крутится без сна и без таймаута. Воркер выходит только по 60s-таймауту `queue:work` — 60 секунд 100% CPU на попытку × 5 попыток на батч. **Прямой остаточный след инцидента: зависшие отправки при деградации приёмника.**

### K3. ✅ `ProcessHelper::sendStopSignal()`: `posix_kill(0, SIGINT)` — сигнал собственной группе процессов
**Исправлено** в `fe04fb0`: при `posix_getpgid() === false` group-kill пропускается; group-kill также пропускается, когда pgid цели совпадает с группой вызывающего. Тесты со счётчиком SIGINT (мёртвый PID, ребёнок в той же группе).
`src/Dispatcher/ProcessHelper.php:44-47`. `posix_getpgid($pid)` может вернуть `false` (процесс умер между проверкой и сигналом) → `-false === 0` → `posix_kill(0, SIGINT)` шлёт SIGINT **всей группе процессов вызывающего** — например, деплой-скрипту, запустившему `slogger:dispatcher:stop`. Плюс `-$pgid` group-kill в принципе бьёт шире цели: дети, порождённые без `setsid`, разделяют pgid мастера.

### K4. ✅ Гонка при takeover: старый мастер удаляет state-файл нового
**Исправлено** в `cdedc8a`: добавлен `DispatcherProcessState::purgeIfOwnedBy(int $masterPid)`, финальный purge мастера выполняется только над собственным state; тесты на чужой pid / свой pid / отсутствие файла.
`src/Dispatcher/Dispatcher.php:41-43` vs `:243`. Новый `start` шлёт SIGINT старому мастеру, делает `purge()` и сохраняет свой state. Старый мастер 1–10 сек гасит детей и в конце **безусловно** вызывает `$processState->purge()` — удаляя файл нового мастера. Итог: работающий диспатчер больше неуправляем (`stop` говорит «not started»), повторный `start` порождает второй флот воркеров на той же очереди.

### K5. ✅ Защита от самотрейсинга — только строчка в конфиге пользователя
**Исправлено** в `9ff0890`: `SendTracesJob::class` захардкожен в `JobWatcher::ALWAYS_EXCEPTED_JOBS` (мёржится с конфигом); `dispatchPushTrace`/`dispatchUpdateTrace` обёрнуты в `withPausedTracing()` с восстановлением предыдущего состояния паузы. Тесты: e2e-диспатч `SendTracesJob` без `excepted`-конфига → 0 job-трейсов; фейковый диспатчер фиксирует паузу в момент create/update.
`src/Watchers/Parents/JobWatcher.php:64` — исключение `SendTracesJob` живёт только в `excepted` публикуемого конфига. У приложения со старым опубликованным конфигом (до добавления этой строки) каждый `SendTracesJob` порождает 2 новых трейс-джоба — **экспоненциальный шторм, сигнатура инцидента**. Плюс `Processor::dispatchPushTrace/dispatchUpdateTrace` (`src/Processor.php:335-343`) не обёрнуты в `handleWithoutTracing`: с database-драйвером очереди INSERT от диспатча трейса сам трейсится DatabaseWatcher'ом; с `only_events` у EventWatcher (обходит встроенный ignore-список `Illuminate\*`) возможна безлимитная рекурсия через `JobQueued`. Захардкодить исключение в коде вотчера + обернуть диспатч.

---

## 🟠 Высокие

### В1. Утечка памяти: `HttpClientWatcher::$requests` никогда не чистится
`src/Watchers/Children/HttpClientWatcher.php:109` — запись на каждый исходящий запрос (traceId + Carbon), `unset` в файле отсутствует вообще (проверено). В долгоживущем воркере/Octane — монотонный рост до рецикла процесса. Родственная утечка той, что дала имя ветке.

### В2. Guzzle-хендлер убивает асинхронность: `$response->wait()` в `Middleware::tap`
`src/Guzzle/GuzzleHandlerFactory.php:53`. `tap`-callback выполняется синхронно в момент композиции промиса — `wait()` блокирует до завершения запроса ещё до возврата из `sendAsync()`. `Http::pool()`/Guzzle concurrency деградирует до 1. Инструментация меняет поведение приложения — чего телеметрия не должна делать никогда.

### В3. Маскирование дырявое (три независимых бага)
1. `MaskHelper::maskValue` (`src/Helpers/MaskHelper.php:84-91`) маскирует **только среднюю треть**: у 40-символьного bearer-токена 27+ символов остаются в открытом виде.
2. Паттерны параметров чувствительны к регистру (`Str::is`): `accessToken`, `Password` — самые ходовые camelCase-ключи — **не маскируются вовсе** (при этом путь заголовков регистронезависимый — значит это недосмотр, а не дизайн).
3. `DatabaseWatcher::maskValue` (`src/Watchers/Children/DatabaseWatcher.php:47-57`): строки ≤5 символов и **все числовые биндинги** уходят в бэкенд без маски — PIN, OTP, номера счетов.

### В4. «Файрвол» вотчеров пробиваем и с дефектной паузой
`src/Processor.php:92-104`: если упал сам путь репортинга ошибки (сломанный лог-канал, disk full) — `handleWatcher` **пробрасывает** `RuntimeException` в хост-приложение, затирая исходное исключение вотчера. Плюс `$paused` — bool, а не счётчик (`src/Processor.php:111-130`): вложенный `handleWithoutTracing` (а он есть уже сейчас: catch → listener) снимает паузу раньше времени — рекурсивная защита отключается ровно там, где нужна.

### В5. Состояние воркера не сбрасывается между джобами
`TraceIdContainer::reset()` — мёртвый код, ноль вызовов (проверено grep'ом). Один незакрытый parent-трейс перманентно отравляет воркер: `$started` навсегда `true`, `stop()` кидает `LogicException`, `JobWatcher::$jobs` подтекает, последующие трейсы цепляются к устаревшему родителю. Нужен санитарный сброс на `JobProcessing`.

### В6. Мониторинг чужих PID сломан: NUL-разделённый cmdline
`src/Dispatcher/ProcessHelper.php:28-39`. В `/proc/<pid>/cmdline` аргументы разделены `\0`, а сохранённый `childCommandName` — строка с пробелами; `trim($cmd, "\0")` срезает только крайние NUL. `str_contains` всегда false → после падения мастера (kill -9/OOM) живые воркеры **неопределимы и неубиваемы** через сохранённый state («child already stopped»).

### В7. State-файл: не атомарен, без локов, corrupt = крах обеих команд
`../../src/Dispatcher/State/DispatcherProcessState.php`: `file_put_contents` без `LOCK_EX`/tmp+rename; параллельный `stop` может прочитать полузаписанный JSON → `json_decode` = null → «array offset on null» — и `start`, и `stop` падают до ручного удаления файла. Два конкурентных `start` оба проходят `getSaved() === null` → двойной флот воркеров.

### В8. Заголовок trace-id ставится в `terminate()` — клиент его не получает
`src/Middleware/HttpMiddleware.php:53-62`. В FPM `terminate()` выполняется после `$response->send()` — заголовки уже отправлены. Кросс-сервисная корреляция трейсов молча не работает в проде; «работает» только в тестах, где response инспектируют после ручного вызова terminate.

### В9. `LogWatcher` мутирует общее событие
`src/Watchers/Children/LogWatcher.php:29-31`: заменяет `$event->context['exception']` (Throwable) на массив **в общем объекте события** — все слушатели после slogger (Sentry, Bugsnag, Telescope) получают уничтоженный exception.

### В10. Синглтоны ядра биндятся в `boot()` вместо `register()`
`src/ServiceProvider.php:47-84`. Провайдер, бутящийся раньше, при резолве `Processor`/`State`/`TraceIdContainer` получит авто-собранный дубликат вне синглтона → два несвязных состояния, молча пропадающие трейсы.

---

## 🟡 Средние

- **`MetricsHelper` — метрики-фикция** (`../../src/Helpers/MetricsHelper.php`): `memory_limit=-1` (дефолт CLI — целевая среда пакета!) и plain-байты не матчатся регекспом → фолбэк 128MB → проценты >100% из воздуха; `512K` → int(0.5)=0 → `DivisionByZeroError` на каждом трейсе (гасится файрволом → весь трейсинг тухнет); «CPU%» = `loadavg*10` без нормализации на ядра — неинтерпретируемо.
- **`MaskHelper::maskArrayByList`** (`:21`): `if (!$realKey)` — ключи `0`, `'0'`, `''` не маскируются; нужно `=== null` (нашли независимо два агента).
- **`CommandWatcher`**: `handleCommandFinished` не перепроверяет `excepted` (`src/Watchers/Parents/CommandWatcher.php:84-95`) — вложенный `Artisan::call('schedule:run')` попает трейс внешней команды: неверная длительность/данные.
- **`Connection`**: `!$chunk` съедает валидный чанк `"0"` (`:193,233`); body-цикл без try/catch в отличие от header-цикла; таймаут сбрасывается каждым чанком — сервер, капающий байт в 4 секунды, держит воркер бесконечно.
- **`SendTracesJob`**: хвост drop-счётчиков логируется только следующим дропом после окна — если дропы прекратились, последние потери не отрепорчены; путь «воркер убил по таймауту на 5-й попытке» обходит drop-политику и всё же кладёт запись в failed_jobs (известное ограничение).
- **`Dispatcher`**: busy-wait 10 сек без sleep при остановке (`:201-217`); рестарт упавших детей без backoff — воркер, падающий на старте, даёт ~86k рестартов/день; SIGINT — не graceful-сигнал для `queue:work` (он ловит SIGTERM) — дети умирают посреди джоба; takeover не ждёт выхода старого мастера → до ~10 сек двойного потребления.
- **`HttpClientWatcher`**: `getHeader(...)[0]` без isset (`:141,188`) — ErrorException при пропущенном request-хуке; non-seekable стримы (`'stream' => true`) роняют `rewind()` — трейс застревает в started и усиливает утечку В1; `getSize() === null` (chunked) обходит 1MB-кап — тело читается в память целиком; внутренние trace-заголовки отправляются наружу третьим сторонам.
- **`json_encode` без `JSON_THROW_ON_ERROR`** во вложенных payload (`TraceCreateObject.php:41`, `TraceUpdateObject.php:39`, `SocketClient.php:66-67`): битый UTF-8 → `false` в payload → на приёме весь `data` заменяется на `['__enc_err' => 'Syntax error']` с ложным диагнозом.
- **`TracesObject::toJson()` разрушает объект** (`array_shift` в итераторах): второй `toJson()` вернёт пустоту, `count()` после сериализации лжёт. Сейчас выживает случайно (в `SendTracesJob` count читается до toJson); поменяй строки местами — и статистика дропов молча обнулится. Сериализатор не должен иметь скрытый write-эффект.
- **Профилирование**: `TraceUpdateObject:37` шлёт `'pr' => null // TODO` — XHProf платит полную цену сбора, данные выбрасываются; вложенные трейсы путают атрибуцию профиля (один bool `profilingStarted`).
- **`DumpWatcher`**: клоберит хендлер приложения; при исключении в `VarDumper::dump` хендлер остаётся снятым до конца процесса.
- **`LocalStorage`**: mkdir-гонка двух процессов → warning → ErrorException у проигравшего.
- **`DataFormatter::stackTrace`**: `array_filter` без `array_values` — JSON-объект `{"0":...,"2":...}` вместо массива; фреймы с `line === 0` теряют строку.
- **`StopDispatcherCommand`**: не верифицирует остановку и не чистит state мёртвого мастера.
- **CI (`../../.github/workflows/tests.yml`)**: `paths`-фильтр не включает `config/**`/`../../composer.json` — их изменения не гоняют CI; cs-fixer в CI не запускается; нет триггера `pull_request`.

---

## 🧪 Покрытие тестами: главные дыры (по риску)

1. **`Dispatcher::start()` — 200 из 356 строк без единого теста**: сигналы pcntl, takeover, рестарт-цикл, graceful shutdown. Протестирован только `stop()`. Все баги K3/K4/В6 пережили зелёные тесты именно поэтому.
2. **Ноль end-to-end тестов реального queue-пайплайна**: `QueueDispatcherTest` — `Bus::fake()` (джоб не сериализуется), `SendTracesJobTest` — прямой вызов `handle()` с моками. Цепочка трейс → payload → воркер → SocketClient → Connection никогда не выполняется целиком; регрессия сериализации (см. K1!) уезжает молча.
3. **`Connection::connect()`/таймауты не покрыты**: тест подсовывает готовый socketpair рефлексией — auth-handshake, stream-ошибки, все ветки таймаутов (ровно те, что работают при аварии приёмника) не выполняются ни одним тестом.
4. **`assertSuccess()` дочерних вотчер-тестов — мёртвый хук**: объявлен abstract, но не вызывается нигде (вызывается только родительская версия). Все тела — `// no action`. Следствие: **payload трейсов (адреса писем, SQL/биндинги, поля schedule, context лога) не ассертится вообще**, кроме отдельных masking-тестов.
5. **Путь ошибок вотчеров не покрыт**: ни один тест не бросает исключение внутри вотчера — `WatcherErrorEvent`/`WatcherErrorListener`/rethrow-баг В4 без регрессионной защиты.
6. **`HttpMiddleware::terminate()` не ассертится** (пропустил бы В8), `QueueDispatcher::__destruct()` не покрыт (финальный флаш хвоста трейсов).
7. **`SLOGGER_ENABLED=false` не тестируется вовсе** — no-op контракт (ранний return провайдера, disabled-ветки) без защиты.
8. **Config-driven регистрация не тестируется**: все вотчеры в тестах регистрируются вручную — `slogger.watchers[].enabled/config` и wiring слушателей через ServiceProvider обходятся; опечатка в схеме конфига пройдёт CI.
9. **Вакуальные тесты**: `TraceDataComplementerTest::testInjectRespectsExcludedFileMasks` — ассерт внутри foreach/if, проходит на пустых данных; `MetricsHelperTest` почти ничего не проверяет; `no-slogger`-роуты и `slogger.exception` в workbench не используются ни одним тестом.
10. **Не покрыты вообще**: Profiling-стек целиком, `MemoryDispatcher` (сам себе тест-харнесс), `ProcessHelper::sendStopSignal()` (K3!), `JobWatcher::handleJobFailed/handleJobReleasedAfterException`, `CacheWatcher` forget-путь.

---

## Приоритеты фиксов (предложение)

| Приоритет | Что | Пункты |
|---|---|---|
| ~~P0 — блокеры релиза~~ ✅ сделано | ключ `dur`/`du`; спин `write()` при `fwrite=0`; `posix_kill(0)`; purge-гонка takeover; хард-исключение SendTracesJob + обёртка dispatchPushTrace | K1–K5 |
| P1 | утечка `$requests`; Guzzle `wait()`; маскирование (треть/регистр/биндинги); файрвол+паузa Processor; сброс состояния воркера; e2e-тест queue-пайплайна | В1–В5, дыры 1–3 |
| P2 | cmdline/state-файл/стоп-сигналы диспатчера; terminate-заголовок; LogWatcher; boot→register; MetricsHelper | В6–В10, средние |
| P3 | остальные средние + вакуальные тесты + CI | — |

Принятые решения, НЕ включённые как баги: немедленный dispatch parent/orphan-трейсов и флаш на каждый update (осознанное поведение, подтверждено автором 2026-07-23); захардкоженные tries/backoff.
