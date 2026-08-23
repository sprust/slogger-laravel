# Ревью ветки `feature/some-20260821` (vs `master`)

**Дата:** 2026-08-23. Объём: 31 коммит, 89 файлов, +10.3k/−1.8k строк — глобальное маскирование, `TraceScope`/резолверы, переработка `Processor`, backoff диспатчера, переработка вотчеров.
**Метод:** 3 параллельных ревью-агента по зонам (маскирование; жизненный цикл трейсов/конкурентность; диспатчер + дочерние вотчеры) + ручная верификация каждой 🔴/🟠 находки по коду и сниппетами.
**Проверки:** cs-fixer чист; phpstan level 8 — 0 ошибок; полный suite в образе `sloggerlaravel-php` — 316 тестов OK.

Легенда: 🔴 баг с потерей данных/крэшем · 🟠 вероятный баг / поведенческая проблема · 🟡 упрощение · ⚪ мелочь.

---

## 🔴 Критические (подтверждено сниппетами)

### K1. Корневой трейс теряет `__add` (значения `TraceDataComplementer::add()`)
`src/Processor.php:413-438`. Блок «unit of work закончился» (`$scope->endUnitOfWork()` → `additional = []`) выполняется **до** `dispatchStopTrace()`, а тот делает `inject($data)` уже по пустому `additional`. Дочерние и вложенные трейсы `__add` получают, **финальный update самого request/job-трейса** — нет. Проверено: child create содержит `"__add":{"user_id":42}`, root update — нет. `UnitOfWorkStateTest` не ловит: проверяет только child `log` и стопит с `data: null`.
**Фикс:** перенести блок `if ($scope->tracesStack === [] && is_null($scope->parentTraceId)) {...}` после `dispatchStopTrace()` + регрессионный тест.

### K2. Крэш маскера на сериализованных строках с объектами → потеря всего батча
`src/Helpers/MaskHelper.php:685` — `unserialize($value, ['allowed_classes' => false])` делает из любого объекта (даже `stdClass`) `__PHP_Incomplete_Class`; далее `maskNode()` (:260) и `mask()` (:859) вызывают `method_exists($value, '__toString')` → `Error: The script tried to call a method on an incomplete object`. Проверено на `serialize(['obj' => (object)['a'=>1], 'x'=>1])`, лежащем где угодно в trace data (значение cache-строки в ModelWatcher, log context…). Исключение вылетает из `SendTracesJob::handle` (:86), 5 ретраев с детерминированным результатом, батч дропается.
**Фикс:** `$value instanceof Stringable` вместо `method_exists(..., '__toString')` в обоих местах; в `maskSerializedString` не заходить в строки с объектами (`preg_match('/[;{]O:\d+:"/')` → вернуть как есть или `FULL_MASK`).

### K3. Shipped `name` в `partial_keys` маскирует структурные поля самих вотчеров
Проверено с дефолтным `config/slogger.php`: `job.name` (`JobWatcher.php:240`) → `App\Jobs\SendEmail` становится `Ap**************il`; `listeners[].name` (`EventWatcher.php:184`) → `Ap***********\X`; файл `request.parameters.avatar.name` → `ph*****pg`; в вложенных payload'ах — `table_name`, `class_name`, `queue_name`, `event_name`, `displayName`. В JSON/XML c парами name/value получается абсурд: `{"name":"password","value":"secret"}` → `{"name":"pa****rd","value":"secret"}`.
**Фикс:** убрать голый `name` из shipped-списка (оставить `username/firstname/lastname/surname`, добавить `full_name`, `fullname`, `middle_name`) **или** переименовать служебные поля вотчеров (`name` → `class`). Побочно: компоненты `pin`, `session`, `otp`, `private`, `pass` целиком маскируют `pin_count`, `session_count`, `otp_sent_at`, `private_ip`, `pass_rate` — допустимо, но стоит осознавать.

---

## 🟠 Высокие

### В1. Backoff диспатчера — блокирующий `sleep()` внутри цикла супервизора
`src/Dispatcher/Dispatcher.php:181-185`. Пока слот N спит до 30 с, остальные слоты не наблюдаются и их stdout/stderr не вычитывается: здоровые воркеры печатают `Processing/Processed` на каждый job → пайп 64 КБ переполняется, воркеры блокируются на записи. Задержки суммируются по слотам (3 сломанных слота = 90 с на итерацию). SIGTERM во время сна прерывает `sleep`, но код всё равно стартует ещё одного воркера и пишет state-файл, и только потом выходит из `while`.
**Фикс:** не спать, а планировать: `$this->restartNotBefore[$index] = time() + $delay;` и `continue`, пока `time() < restartNotBefore` — цикл и так тикает раз в секунду; сигналы и вывод остальных слотов обслуживаются сразу.

### В2. Счётчик backoff сбрасывается, если воркер прожил ~1 секунду
`Dispatcher.php:165-168`. `restartFailures[$index] = 0` ставится на первой же итерации с `isRunning() === true`, т.е. через `sleep(1)` после рестарта. Воркер, умирающий через 2–5 с (таймаут коннекта к Redis при boot, падение на первом job), в backoff никогда не попадает — рестарт каждые ~3–6 с «вечно», что противоречит цели коммита `4710f96`.
**Фикс:** помнить `startedAt[$index]`, сбрасывать счётчик только если процесс прожил ≥ N секунд (например 60).

### В3. `stop($previousState)` может послать SIGTERM самому себе при переиспользовании PID
`Dispatcher.php:50-51` + `:284-286`, `ProcessHelper::isPidActive` сравнивает только cmdline, обработчики сигналов ставятся *после* `stop()` (:80-81). Сценарий: контейнер с state-файлом на volume; после рестарта контейнера мастер получает тот же маленький pid, что в файле, cmdline содержит `slogger:dispatcher:start` → `posix_kill(<свой pid>, SIGTERM)` с дефолтным действием → новый мастер умирает до старта, и так по кругу. Вероятность средняя, механика подтверждена.
**Фикс:** в `stop()`/`sendStopSignal` пропускать `$pid === getmypid()`; опционально ставить `pcntl_signal` до `stop()`.

### В4. `NotificationWatcher::getRecipients` теряет адрес для маршрута `[email => name]`
`src/Watchers/Children/NotificationWatcher.php:90-95`. `Notification::route('mail', ['a@x.com' => 'Name'])` — документированная форма; `implode(',', $route)` идёт по значениям → в `recipients.mail` попадает `"Name"`, адрес потерян.
**Фикс:** для массива со строковыми ключами собирать `['email' => ..., 'full_name' => ...]` как в `MailWatcher` — тогда его и value-pattern `email` достанет.

### В5. Owned detached-трейс умершей корутины никогда не сметается
`src/Processor.php:623-660`. TTL-ветка применяется только к `owner_trace_id === null` и чужому `owner_scope_id`. Корутина открыла parent P, внутри него outbound D (`owner_trace_id = P`), корутину убили жёстко → `stop(P)` никто не вызовет, `stopInterruptedDetached(null)` D не тронет. Проверено агентом на `FakeCoroutineScopeResolver`: 0 update-ов, запись в `$detachedTraces` остаётся навсегда (утечка + `started`-трейсы). Комментарий к `DETACHED_TRACE_TTL_SECONDS` обещает обратное.
**Фикс:** применять TTL к любому detached-трейсу независимо от владельца; тогда `owner_scope_id` можно выбросить (см. У3).

### В6. Профайлер проверяет `Fiber::getCurrent()`, а не резолвер
`src/Profiling/AbstractProfiling.php:41`. Swoole-корутины — не PHP Fiber: приложение с собственным `TraceScopeResolverInterface` (README прямо предлагает) получит xhprof, меряющий все перемешанные корутины. Обратная сторона: под `ProcessTraceScopeResolver` любая библиотека, крутящая код в Fiber (amphp/Revolt, Reverb), молча выключает профилирование. `isConcurrent()` в `src/` не используется никем (только тест).
**Фикс:** инжектить резолвер и `if ($this->scopeResolver->isConcurrent()) return;` — единственное осмысленное применение `isConcurrent()`; либо удалить метод из интерфейса.

### В7. Не тот лимит для XML request body
`src/Watchers/Parents/RequestWatcher.php:659,667` — `readXmlRequestBody()` сравнивает с `$this->maxResponseBytes` (`output.max_content_length`), а должен с `maxRequestBytes` (как :578/:600). `input.max_content_length` для XML-запросов игнорируется.

### В8. Маскирование: O(keys × needles) без кэша
`MaskHelper.php:769-822` — `modeFor()` на каждый ключ делает `preg_split` + `Str::lower` + `Str::is()` по ~60 needles для целого ключа и каждого компонента; `Str::is` собирает regex заново на каждый вызов. Замер: 100k листовых ключей — 3.5 с с shipped-конфигом против 0.22 с с одним needle. Тело 1 МБ JSON легко даёт столько ключей → воркер тратит секунды на трейс.
**Фикс:** компилировать каждый список один раз в одну регулярку (`/^(?:auth|token|.*token.*|…)$/`) и мемоизировать `modeFor` по ключу внутри `$rules`.

### В9. Коллизия ключей при маскировании теряет запись
`MaskHelper.php:244` — проверка только когда замаскированный ключ изменился. `['john@a.com' => 1, 'jo******om' => 2]` → `{"jo******om": 2}`, единица потеряна молча (проверено), хотя комментарий обещает обратное. Редкий кейс — либо проверять `array_key_exists` всегда, либо выкинуть логику и описать.

---

## 🟡 Упрощения (главная просьба: проще и чище)

### Ядро (`Processor` вырос с 368 до 715 строк)
- **У1. Один `stop()` вместо `stop()` + `stopDetached()`** (`Processor.php:361-486`). Они вызывают друг друга «на случай путаницы»; `stop()` уже сам обрабатывает detached. `HttpClientWatcher` → звать `stop()`. −40 строк.
- **У2. `handleWithoutTracing()` ≡ `withPausedTracing()`** (:162-178 и :701-714) — одинаковые тела. `dispatchPushTrace/UpdateTrace` → `handleWithoutTracing`. −15 строк.
- **У3. TTL для всех detached-трейсов** вместо трёхуровневой логики owner / owner_scope / TTL (:623-660). Закрывает В5 и убирает `owner_scope_id`, `TraceScope::$ownerId` (останется только для `FiberTraceScopeResolver`), `ROOT_OWNER_ID`-дубль. Цена: ownerless outbound без родителя закроется через 5 мин, а не в конце запроса.
- **У4. `preParentTraceId` — мёртвое состояние** (`TraceIdContainer.php`, `TraceScope.php:30`, `Processor.php:330-335`). `push()` читает pre только когда `parentTraceId === null`; но `isActive()` ⇒ parentTraceId задан, а при `canBeOrphan` и пустом стеке `reset()` уже обнулил обе. Комментарий «when a parent is in excluded» — из старой реализации. Убрать `preParentTraceId`, `getPreParentTraceId()`, `reset()`, недостижимый `LogicException` в `push()`. После этого `TraceIdContainer` — один getter над `scope()->parentTraceId` → свернуть в `Processor::currentParentTraceId()`; `HttpMiddleware`/`JobWatcher` инжектить только `Processor`. `HttpClientWatcher:39` инжектит `TraceIdContainer` и **не использует**.
- **У5. Четыре вотчера ведут учёт открытых трейсов тремя способами.** Request/Command: стек в `TraceScope::$watcherStacks` (4 метода, ~65 строк) + `onTraceInterrupted`; Job: `$this->jobs[$uuid]` без `onTraceInterrupted`; HttpClient: `$this->requests[$traceId]` с дублирующим `'trace_id'` внутри + `onTraceInterrupted`. Стек вотчера — вторая копия стека Processor-а в той же области. Один стиль: карта `trace_id ⇒ meta` на вотчере + `onTraceInterrupted` для зачистки; тогда `TraceScope` теряет `$watcherStacks`, 4 метода и зависимость от `WatcherInterface` и становится структурой из 4 полей.
- **У6. `Processor` совмещает реестр вотчеров + firewall + жизненный цикл.** `registerWatcher()` используется только `ServiceProvider::registerWatchers()` — перенести туда. `handleSeparateTracing()` (:186) не вызывается нигде в `src/`, тестах и README — удалить или задокументировать.
- ⚪ `AbstractProfiling::$profilingStarted` ≡ `ownerTraceId !== null` — одно поле; `release()` = `stop()` с отброшенным результатом. `TraceScope::currentParentTraceId()` — getter публичного свойства. `CommandWatcher::handleCommandFinished` оборачивает в `handleWatcher` повторно (уже обёрнуто в `registerEvent`); `?CommandStarting $event` никогда не null.

### Маскирование (850 строк хелпера + 3 класса)
- **У7. Тяжёлая конфигурационная обвязка.** `MaskingConfig::shippedDefaults()` (:81-94) читает `config/slogger.php` с диска; `readKeys` фильтрует список, затем `prepareNeedles/preparePatterns` (`MaskHelper.php:169-207`) валидируют его *снова на каждый трейс*. Проще: `mergeConfigFrom(__DIR__.'/../config/slogger.php', 'slogger')` в `ServiceProvider` (его сейчас нет вообще) закрывает кейс «опубликованный конфиг без секции masking»; `MaskingConfig` сводится к трём `config()`; правила компилировать один раз в `TraceDataMasker` (вместе с В8).
- **У8. `applyValuePattern` (:388-433, 45 строк)** → `preg_replace_callback(..., PREG_OFFSET_CAPTURE)` ~12 строк; ветка `if ($matchedOffset < $cursor)` мёртвая.
- **У9. Четыре копии одного лимита:** `MaskHelper::MAX_STRING_LENGTH`=1 000 000, `BodyDecoder::MAX_BODY_BYTES`=1 000 000, `HttpClientWatcher::MAX_BODY_BYTES`=1 000 000, `RequestWatcher` дефолт 1 048 576. Тело 1 000 001..1 048 576 байт проходит вотчер и становится `__skipped: body_too_large`. Одна публичная константа.
- **У10. XML парсится дважды, первый раз — в трейсируемом приложении.** `BodyDecoder::isXml` делает полный `DOMDocument::loadXML` до 1 МБ в request path, потом маскер парсит снова в воркере — противоречит «приложение не платит». Достаточно content-type + дешёвого sniff'а `<html`/`<!DOCTYPE html`. Ветки с UTF-16 BOM в `trimForParsing` мёртвые.
- **У11. `TraceDataMasker::maskTraces`** строит новый `TracesObject`, хотя мутирует по месту; `mask()` дублирует проверку `enabled`. Плюс: любое исключение маскера = потеря батча × 5 ретраев (`SendTracesJob.php:86`) — маскирование детерминированное, ретраи бессмысленны; `try/catch` на трейс с заменой `data` на `['__mask_error' => ...]` (fail closed).
- ⚪ JSON глубже 512 не декодируется и уходит **немаскированным** целиком — либо больший depth, либо `FULL_MASK` при `JSON_ERROR_DEPTH`. README «an object → ********» не совпадает с кодом (объект под совпавшим ключом разворачивается и маскируется полистно). `MaskingConfig.php:16` всё ещё говорит «substrings». `prepareNeedles` через `array_filter` выкидывает маску `"0"`.

### Диспатчер и дочерние вотчеры
- **У12. `Dispatcher::start()` — ~240 строк, три фазы в одном методе.** Вынести `supervise()`, `shutdown()`; `dispatcher`/`masterPid`/`childCommandName` — в поля, тогда `freshState()` (3 вызова с 5 одинаковыми named-args) и `makeLogMessage` станут однострочниками. `stripExecPrefix` — знание о формате команды `QueueDispatcherProcessor`, лучше отдавать готовое имя оттуда.
- **У13. `DatabaseWatcher` пишет bindings, которые после маски не несут информации** (`:32-34, 60-73`): из любой строки `********`, из int `0` — массив одинаковых заглушек. Проще при включённом маскировании не писать bindings (или писать `bindings_count`) и убрать зависимость от `TraceDataMasker` и рекурсивный `maskValue`.
- **У14. `HttpClientWatcher`:** две копии чтения тела (`:306-341` и `:368-428`) в разном порядке проверок → один `readBody(StreamInterface, string $contentType)`. `isSubscribeRequest()` всегда `true`; `$requests` хранит `trace_id` и ключом, и внутри значения; `getRequestPath()` возвращает полный URL с query и userinfo.
- **У15. `CacheWatcher`:** четыре почти одинаковых хендлера → один `pushCache(type, key, ?entry)`; `shouldHideValue()` всегда `false`, `prepareValue($key, …)` берёт `$key` только ради него.
- ⚪ `DumpWatcher` хранит `$config` только чтобы перерегистрировать хендлер — достаточно хранить closure. `MailWatcher` — недостижимая ветка Swift (`array<string,string>`), «not tested» в докблоках Mail/Notification — неправда. `NotificationWatcher`: `class_implements` вместо `instanceof`; `'target' => ['recipients' => …]` — единственный ключ. `DispatcherProcessState`: `readonly $staticUid` с литералом = `private const`; `LOCK_EX` на per-pid temp-файле ничего не защищает; `try/catch` вокруг `@file_get_contents` в `ProcessHelper::isPidActive` мёртв. `MetricsHelper`: без `/proc/cpuinfo` `cpuCount = 1` → load 4 на 8 ядрах = 400%; `memory_limit=1.5G` не парсится → `null`. `QueueDispatcherProcessor.php:27`: `app(WorkCommand::class)->getName()` ради литерала `queue:work`. `ServiceProvider.php:262-274`: два комментария слиплись. README:56 не говорит, что скалярное `add()` живёт до конца текущей единицы работы (в Octane — стирается после первого запроса, для процесса — только Closure).

---

## Проверено и НЕ подтвердилось
Катастрофический backtracking shipped-паттернов (900 КБ + email — 6 мс); невалидный UTF-8 в маскере (ничего не бросает); Closure/Generator/JsonSerializable/enum в маскере; XML с cp1251; `query_string` на top level; URL userinfo; рекурсия по JSON-в-строке; composer `^10.26` vs используемые API Laravel 10 (`singletonIf`, `Str::is`, `publishes(paths:, groups:)`); `release.yml`; `SendTracesJob` drop-policy vs `markJobAsFailedIfAlreadyExceedsMaxAttempts`; `TraceDataComplementer` closure/value split; `MetricsHelper` (−1, единицы, деление на ноль, кэш) — README совпадает с кодом.

---

## Приоритеты

| Приоритет | Что | Пункты |
|---|---|---|
| P0 — до релиза | `__add` у корневого трейса; крэш маскера на `__PHP_Incomplete_Class`; убрать `name` из shipped partial_keys (или переименовать поля вотчеров) | K1–K3 |
| P1 | backoff без `sleep` + сброс счётчика по времени жизни; self-SIGTERM; `[email => name]` в Notification; TTL для всех detached; XML-лимит; профайлер через `isConcurrent()`; компиляция правил маскера | В1–В8 |
| P2 — упрощения ядра | один `stop()`, один «паузер», выкинуть `preParentTraceId`/`TraceIdContainer`, единый учёт открытых трейсов в вотчерах, `mergeConfigFrom` вместо `MaskingConfig::shippedDefaults` | У1–У7 |
| P3 | остальные упрощения и мелочи | У8–У15, ⚪ |

Общее впечатление: идеи правильные и компактные (`TraceScope` + резолверы ≈ 120 содержательных строк; walk-маскер вместо dot-flatten; атомарный state-файл), но вокруг них наросло много страховочного кода «на случай путаницы», дублей и параллельных механизмов, которые при этом всё равно пропускают по одному классу ошибок каждый. Самые выгодные упрощения — У1+У2+У4 (−~100 строк и −1 класс в ядре), У5 (один способ учёта открытых трейсов), У7+В8 (маскер с предкомпилированными правилами вместо per-trace валидации конфига).

---

## План исправлений (2026-08-23, по решению автора: K1–K3 и 🟠, затем упрощения)

Правила выполнения: работа в текущей ветке `feature/some-20260821`, **без коммитов и пушей** (автор коммитит сам). На каждый баг — регрессионный тест. После каждого этапа: cs-fixer, phpstan L8, полный suite в образе `sloggerlaravel-php` (локальный php без pdo_sqlite).

Разделение по непересекающимся файлам (три потока параллельно):

### Поток A — ядро (Processor / Traces / Profiling / родительские вотчеры / HttpClientWatcher / HttpMiddleware)
- [x] K1 `__add` у корневого трейса: блок `endUnitOfWork` после `dispatchStopTrace()` + тест `UnitOfWorkStateTest::testTheRootTracesOwnFinalUpdateCarriesTheAddedValues`
- [x] В5/У3 `owner_scope_id` убран; `sweepExpiredDetached()` по TTL вызывается на каждом закрытии родительского трейса (а не только в конце единицы работы — под корутинами она не наступает). Тесты `ProcessorTest::testADetachedTraceNobodyOwnsIsSweptOnceItIsOldEnough`, `ConcurrentTracingTest::testADetachedTraceOfAKilledCoroutineIsSweptByAge`
- [x] В6 профайлер инжектит резолвер и спрашивает `isConcurrent()`; `$profilingStarted` слит с `ownerTraceId`. Тесты: скип под `FakeCoroutineScopeResolver` + библиотека в фибере под обычным процессом профилирование НЕ выключает
- [x] У1 `stopDetached()` удалён, `stop()` закрывает обе формы
- [x] У2 `withPausedTracing` удалён
- [x] У4 `TraceIdContainer` удалён целиком, `preParentTraceId`/`reset()`/`LogicException`/`TraceScope::currentParentTraceId()` тоже; `Processor::currentParentTraceId()` — единственный getter
- [x] У5 новый `SLoggerLaravel\Watchers\OpenTraces` (open/take/takeInnermost/forget/count) у Request/Command/Job/HttpClient; `$watcherStacks` и 4 метода выкинуты из `TraceScope`, зависимость от `WatcherInterface` тоже. `OpenTracesTest`
- [x] У6 сделано; вотчер дополнительно кладётся в контейнер через `app->instance()` — его учёт открытых трейсов теперь живёт на нём, и второй экземпляр был бы вторым несвязанным набором
- [x] У14 один `readBody(StreamInterface, string)`, `isSubscribeRequest` и дубль `trace_id` убраны, `getRequestPath` → `getRequestUri`; чтение тела ответа стало полностью ленивым
- [x] ⚪ всё сделано; `CommandStarting`/`CommandFinished` больше не nullable

### Поток B — маскирование (MaskHelper / MaskingConfig / BodyDecoder / TraceDataMasker / SendTracesJob / config / README)
- [x] K2 `instanceof Stringable` вместо `method_exists` (оба места); `maskSerializedString` не заходит в блобы с `O:`/`C:`/`E:` + тесты `MaskHelperTest::testASerializedBlobHoldingAnObjectIsLeftForTheValuePatterns`, `::testAnIncompleteObjectIsMaskedRatherThanThrownOn`
- [x] K3 голый `name` убран из shipped `partial_keys`; добавлены `user_name`, `nickname`, `first_name`, `last_name`, `middlename`, `middle_name`, `fullname`, `full_name`, `*first_name*`, `*last_name*` (снейк-кейс держался только на компоненте `name`). Синхронизированы `config/slogger.php`, `workbench/config/slogger.php`, README/README.ru + тесты `WatcherDataReachableByMaskingTest::testTheStructuralNamesOfAWatcherStayReadable`, `::testAPersonsNameIsStillMaskedWhereItIsSpeltOut`, правлен `ModelWatcherTest`
- [x] В8 новый `MaskingRules`: одна regex на список, мемоизация `modeFor` с потолком 10k ключей. Бенчмарк-тест на 20k ключей
- [x] В9 сделано + тест
- [x] У7 компиляция один раз в `TraceDataMasker` — сделано. **`mergeConfigFrom` НЕ добавлен осознанно:** он мержит только верхний уровень, то есть закрывает лишь случай «секции `masking` нет вовсе», а `shippedDefaults()` даёт фолбэк по каждому списку отдельно (опубликованный конфиг с `full_keys`, но без `partial_keys`). Замена была бы регрессией; чтение файла происходит один раз на процесс (синглтон), в горячем пути его нет
- [x] У8 мёртвая ветка `if ($matchedOffset < $cursor)` и лишние касты убраны. **Оставлен `preg_match_all`:** phpstan L8 знает свою сигнатуру колбэка `preg_replace_callback` и игнорирует `PREG_OFFSET_CAPTURE`, из-за чего вариант с колбэком даёт 3 ошибки, которые нечем подавить кроме baseline
- [x] У9 `MaskHelper::MAX_READABLE_BYTES`; на неё ссылаются `BodyDecoder`, `HttpClientWatcher` и дефолты `RequestWatcher`; в конфигах и README `max_content_length` тоже 1000000
- [x] У10 `isXml` — дешёвый sniff (`<!DOCTYPE html` / `<html`), UTF-16 BOM-ветки удалены. **Следствие:** тело, объявленное как XML и не распарсившееся, теперь записывается — и маскер закрывает его целиком (`FULL_MASK`) по ключу `__xml`, то есть проверка переехала туда, где пакету можно тратить время
- [x] У11 сделано. Потребовало **сверх плана** починить `TracesObject::iterateCreating/iterateUpdating` — они `array_shift`-ом опустошали объект, и мутация по месту отдавала бы отправителю пустой батч (⚪ из старого ревью)
- [x] ⚪ всё сделано; маска `"0"` работала уже после переписывания компиляции списков — закреплено тестом

### Поток C — диспатчер и дочерние вотчеры (Dispatcher / ProcessHelper / State / Cache / Database / Dump / Mail / Notification / MetricsHelper)
- [x] В1 `mayRefillSlot()` + `restartNotBefore[$index]`, `sleep()` из цикла убран + тест
- [x] В2 `settleSlot()` + `slotStartedAt[$index]`, порог `SETTLED_UPTIME_SECONDS = 60` + тест
- [x] В3 гвард в `ProcessHelper::sendStopSignal()`; плюс `pcntl_signal` теперь ставится ДО `stop()` в `Dispatcher::start()` + тест
- [x] В4 `formatRoute()` разбирает все три документированные формы + 2 теста; заодно `target.recipients` → `recipients` (единственный ключ)
- [x] В7 сделано + тест с разными `input`/`output` капами (одинаковые капы баг не ловили)
- [x] У12 `start()` → `supervise()`/`shutdown()`/`idleWhileDisabled()`/`saveState()`/`fail()`; контекст в полях; `stripExecPrefix` удалён, имя даёт `DispatcherProcessorInterface::getChildCommandName()` (**расширение интерфейса — BC-break для сторонних реализаций**)
- [x] У13 `describeBindings()`: при включённом маскировании — только `bindings_count`. **Зависимость от `TraceDataMasker` оставлена:** он и есть выключатель `isEnabled()`, рекурсивный `maskValue` удалён
- [x] У15 сделано, четыре хендлера — однострочники
- [x] ⚪ всё сделано. `DumpWatcher` дополнительно возвращает хендлер через `finally`; `MetricsHelper` при нечитаемом `/proc/cpuinfo` отдаёт `null` вместо cpuCount=1, и `memory_limit=1.5G` парсится как 1G (как это делает сам PHP) + тесты

### Финал
- [x] cs-fixer 0/173, phpstan L8 — 0 ошибок, 338 тестов OK (было 316). README и README.ru синхронизированы: K3, У13 (`bindings_count`), В4 (`recipients`), У9 (1000000), У10, В6, объект в маскере, время жизни `add()`
- [x] статусы обновлены, отчёт отправлен; коммитов и пушей нет

### Выполнено 2026-08-23

**K1–K3 закрыты.** Проверки после этапа: cs-fixer — 0 из 171, phpstan level 8 — 0 ошибок,
сюит в образе `slogger-php83` — 321 тест OK (было 316; +5 регрессионных).

Каждый регрессионный тест проверен на падение против исходного кода:
K1 — `null` вместо `['user_id' => 4242]`; K2 — ровно тот самый
`Error: The script tried to call a method on an incomplete object` из
`MaskHelper.php:260`; K3 — `Ap**************il` вместо `App\Jobs\SendEmail`.

Замечания по ходу:
- образа `sloggerlaravel-php` в системе нет, использован `slogger-php83` (PHP 8.3.33,
  pdo_sqlite/pcntl/posix);
- в рабочем дереве уже лежал незакоммиченный частичный фикс K1, потерявший
  `traceIdContainer->reset()` — из-за него падал `InterruptedTraceNotificationTest`; вызов
  возвращён внутрь блока;
- `workbench/config/slogger.php` — четвёртая копия конфига (расходится с `config/slogger.php`
  только отсутствием `timeout_seconds`); списки маскирования правились в обеих;
- по K3: `first_name`/`last_name` маскировались **только** через компонент `name`, поэтому
  снейк-кейс добавлен явно — иначе фикс молча снял бы маску с самых ходовых PII-ключей;
- по K2: блоб с объектом теперь не разбирается вовсе, ключевые списки внутрь него не
  достают — value-паттерны применяются к нему как к обычной строке. Это осознанный размен,
  описан в README/README.ru.

### Выполнено 2026-08-23 (продолжение): 🟠 В1–В9 и упрощения У1–У15 + ⚪

Проверки после этапа: cs-fixer 0 из 173, phpstan level 8 — 0 ошибок,
сюит в образе `slogger-php83` — **338 тестов OK** (было 316 до начала работ).

Новые файлы: `src/Helpers/MaskingRules.php`, `src/Watchers/OpenTraces.php`,
`tests/Feature/Watchers/OpenTracesTest.php`. Удалён `src/Traces/TraceIdContainer.php`.

Отклонения от плана, каждое с причиной, — см. пометки в пунктах У7, У8, У13 выше.
Изменения поведения, которые стоит знать при релизе:

- **BC:** `DispatcherProcessorInterface` получил `getChildCommandName()`.
- **BC:** `Processor::stopDetached()`, `Processor::handleSeparateTracing()`,
  `Processor::registerWatcher()` и класс `TraceIdContainer` удалены.
- **Форма данных:** `target.recipients` → `recipients`; `db.bindings` →
  `db.bindings_count` при включённом маскировании.
- **Поведение:** ownerless detached-трейс закрывается по TTL (5 мин), а не в конце
  чужой единицы работы; тело, объявленное XML и не распарсившееся, записывается и
  маскируется целиком в воркере; профилирование выключает резолвер, а не `Fiber`.
