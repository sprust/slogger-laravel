# План: локальный Go-прокси для трейсов (`feature/local-proxy`)

База: локальный `master` = `origin/master`, коммит `269a159` (`v2.2.0`). Ветка уже создана:
`feature/local-proxy`.

Этот документ — план реализации, а не отчёт о реализации. Код прокси пока не написан.

## 1. Что подтверждено по текущему проекту

- PHP socket-клиент использует потоковый socket (TCP или Unix domain socket) и framing
  `uint32 big-endian length + payload`: `src/Dispatcher/ApiClients/Socket/Connection.php`.
- При подключении клиент отправляет JSON `{"t":"..."}`; сервер обязан ответить framed
  `ok`. Затем на одном соединении может быть много последовательных запросов с трейсами;
  на каждый клиент ждёт framed `received`.
- Тело запроса имеет вид `{"c":"[...]","u":"[...]"}`: значения `c` и `u` — JSON-строки
  с массивами create/update-операций, а не сразу массивы. Это важно для объединения пачек
  без потери или случайного изменения полей.
- Маскирование выполняется в `SendTracesJob` до `SocketClient`: на диск прокси попадут уже
  замаскированные данные. Файлы всё равно считаем чувствительными и создаём с ограниченными
  правами.
- Текущий PHP-клиент сам делает один reconnect после закрытия соединения, а джоба делает до
  пяти попыток. Прокси должен корректно переживать повтор одной и той же пачки.
- Консольных команд сейчас нет; `ServiceProvider::registerConsole()` только публикует конфиг.
- Релиз создаёт `.github/workflows/release.yml` из `composer.json -> version`, тег `vX.Y.Z`
  неизменяем. Бинарь нужно прикрепить именно к этому GitHub Release, а не хранить в git.
- Основные проверки: cs-fixer, phpstan level 8, PHPUnit; Go в локальном окружении сейчас не
  установлен, поэтому toolchain добавляется в существующий PHP-контейнер и все Go-команды
  выполняются через `docker-compose exec php`, без отдельного Go-контейнера.

## 2. Цель и эксплуатационные гарантии

После установки приложение переключается на прокси одной настройкой типа клиента:

```dotenv
SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE=proxy
```

`direct` — текущее прямое подключение по `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL`, `proxy` —
Unix socket `local-proxy.sock` в настроенном каталоге трейсов (по умолчанию
`storage/slogger/traces/local-proxy.sock`). Локальный URL и имя Unix socket для proxy-режима не
настраиваются; каталог задаётся отдельным env относительно корня проекта.

Прокси:

1. принимает локальные подключения без проверки токена;
2. подтверждает пачку только после durable-записи в hourly-файл;
3. независимо отправляет накопленные пачки в настоящий SLogger;
4. после рестарта продолжает с сохранённой позиции;
5. удаляет отправленные файлы и раз в 10 минут чистит просроченные;
6. работает одним процессом под Supervisor и корректно останавливается максимум за 5 секунд.

### Инварианты

- Семантика доставки — **at least once**, не exactly once. Дубликат возможен, если процесс
  упал после записи/отправки, но до сохранения подтверждения или offset. Это осознанная плата
  за отсутствие потерь при таких crash window; SLogger должен оставаться терпимым к повторам.
- `received` клиенту нельзя отправлять до успешного `write + fsync` соответствующей записи.
- Offset продвигается только после `received` от настоящего SLogger.
- Сбой upstream не продвигает offset и не роняет накопленные данные. Единственная причина
  удалить неотправленные данные — настроенный retention.
- Один writer на процесс; никаких конкурентных `append` в один файл из разных goroutine.
- Активный hourly-файл нельзя удалить только потому, что reader временно догнал EOF: после EOF
  в него ещё могут дописывать. Удаляем его после закрытия часа и полной отправки.
- Повреждение в середине файла не маскируем молчим сбросом offset: процесс останавливается с
  понятной ошибкой. Безопасно восстанавливаем только незавершённый хвост после crash.

## 3. Структура Go-кода

Новый независимый модуль внутри репозитория:

```text
local-proxy/
├── go.mod
├── go.sum
├── cmd/
│   └── slogger-local-proxy/
│       └── main.go
└── internal/
    ├── app/
    │   └── app.go
    ├── config/
    │   └── config.go
    ├── protocol/
    │   └── frame.go
    ├── storage/
    │   ├── storage.go
    │   └── state.go
    ├── server/
    │   └── server.go
    ├── logging/
    │   └── logger.go
    └── sender/
        ├── sender.go
        └── batch.go
```

Тесты лежат рядом с пакетами (`*_test.go`), интеграционные сценарии процесса — в
`local-proxy/internal/app` или отдельном `local-proxy/integration` с явным build tag, если они
мешают обычному короткому `go test ./...`.

Принципы:

- `main.go` только парсит флаги, загружает конфиг, создаёт logger и запускает `app.Run`;
  бизнес-логики и глобальных переменных в нём нет.
- Внутренние пакеты получают зависимости через конструкторы и интерфейсы в месте использования.
- Часы, файловые операции, notification и upstream в тестах подменяются без `sleep`-based
  хрупких проверок там, где это возможно.
- Контекст отмены проходит сверху вниз; горутины запускает и дожидается владелец приложения.
- Ошибки оборачиваются с операцией и именем файла/адресом через `%w`; логируются один раз на
  соответствующем уровне, а не на каждом уровне стека.
- Логи — `log/slog`: info/debug в stdout, каждая error-запись дополнительно идёт в stderr и daily
  error-файл в каталоге трейсов. Токены и payload никуда не попадают. Supervisor продолжает собирать
  stdout/stderr, а трёхдневная файловая ротация относится только к error-логам прокси.
- Зависимости минимальны. Для чтения Laravel-совместимого `.env` использовать
  `github.com/joho/godotenv`; остальное по возможности стандартная библиотека. Не писать
  собственный частичный парсер `.env`, который иначе трактует кавычки и комментарии.
- Весь Go-код проходит `gofmt`, `go vet`, `go test -race ./...`.

## 4. Конфигурация и запуск

### 4.1. Переменные окружения

| Переменная | Назначение | Значение по умолчанию |
| --- | --- | --- |
| `SLOGGER_TOKEN` | Токен только для upstream SLogger | обязательное, непустое |
| `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE` | Выбор адреса PHP socket-клиента: `direct` или `proxy` | `direct` |
| `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL` | Постоянный адрес настоящего SLogger для PHP в `direct` и для Go-прокси в `proxy` | обязательное |
| `SLOGGER_LOCAL_PROXY_TRACES_PATH` | Каталог трейсов, socket, state и error-логов относительно корня проекта | `storage/slogger/traces` |
| `SLOGGER_LOCAL_PROXY_RETENTION_HOURS` | Возраст hourly-файла, после которого его можно удалить | `24` |

`SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE` читает только PHP. При переключении на `proxy`
`SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL` не меняется: Go-прокси использует его как upstream,
а PHP-клиент вместо него подключается к фиксированному Unix socket. Отдельный upstream env не
нужен.

Правила загрузки:

- Команда запускается из корня Laravel-приложения и по умолчанию читает `./.env`.
- Флаги Go-процесса: `-env` (путь к `.env`) и `-version`.
- `SLOGGER_LOCAL_PROXY_TRACES_PATH` нормализуется одинаково в PHP и Go: убираются все ведущие и
  завершающие `/`, затем очищаются повторные разделители. Значения `/storage/slogger/traces/`,
  `storage/slogger/traces`, `/storage/slogger/traces` и `storage/slogger/traces/` эквивалентны и
  ведут в `<project-root>/storage/slogger/traces`, а не в корень файловой системы.
- Пустой после нормализации путь, `.` и любой сегмент `..` запрещены. Итоговый путь всегда строится
  через `base_path()` в PHP и рабочий каталог проекта в Go; абсолютные пути вне проекта не
  поддерживаются. Изменение пути не переносит старую очередь автоматически — это отдельный каталог
  со своими state/lock.
- Реальные environment variables процесса имеют приоритет над значениями `.env`, чтобы
  Supervisor мог переопределять конфигурацию без редактирования файла.
- Локальный listener всегда Unix domain socket с неизменяемым именем `local-proxy.sock` внутри
  нормализованного `SLOGGER_LOCAL_PROXY_TRACES_PATH`; TCP listener и настройка локального URL/имени
  отсутствуют. PHP получает тот же путь через
  `base_path($tracesPath . '/local-proxy.sock')` и превращает его в `unix://<absolute-path>`.
- Upstream прокси остаётся `tcp://` и читается из
  `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL`; URL обязан содержать host и валидный port.
- Retention — целое число часов не меньше `1`; некорректные значения останавливают старт.
- Для соединения **Go-прокси → сервер SLogger**: connect timeout — 10 секунд, write timeout —
  20 секунд, read timeout ожидания `ok` или `received` — 20 секунд. Это фиксированные значения, а
  не новые env; deadlines устанавливаются на каждую попытку и перед каждой фазой обновляются.
- Пустой auth-frame локального клиента допустим; пустой `SLOGGER_TOKEN` для upstream — нет.

В `config/slogger.php` и `workbench/config/slogger.php`:
- `dispatchers.queue.api_clients.socket.type` читает
  `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE` с default `direct`;
- существующий `dispatchers.queue.api_clients.socket.url` остаётся адресом настоящего SLogger;
- новая секция `local_proxy` содержит `traces_path` и `retention_hours`.

В комментарии `local_proxy.traces_path` в обоих config-файлах указать, что путь относительный к
корню проекта и записи `/storage/slogger/traces/` и `storage/slogger/traces` эквивалентны:
ведущие/завершающие слеши игнорируются.

Go читает из основного `.env` `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL`,
`SLOGGER_LOCAL_PROXY_TRACES_PATH`, `SLOGGER_LOCAL_PROXY_RETENTION_HOURS` и общий `SLOGGER_TOKEN`;
client type, PHP и Laravel он не загружает.

### 4.2. Выбор адреса в PHP socket-клиенте

`DispatcherQueueConfig` получает `getSocketClientType()`, `getLocalProxyTracesPath()` и единый
`getSocketClientAddress()`:

- `direct` возвращает существующий `getSocketClientUrl()`; поведение и обязательность старого URL
  не меняются;
- `proxy` нормализует configured traces path и возвращает
  `unix://` + `base_path($tracesPath . '/local-proxy.sock')`, не обращаясь к
  `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL`;
- любое другое значение вызывает понятное исключение с допустимыми `direct|proxy`.

`ApiClientFactory::createSocket()` передаёт в `Connection` уже выбранный адрес. Сам `Connection`
менять не нужно: `stream_socket_client` уже поддерживает Unix domain socket. Имя
`local-proxy.sock` фиксируется константой на PHP- и Go-сторонах; env, опций Artisan и CLI для его
изменения нет.

### 4.3. Файлы

Относительно корня Laravel-приложения, внутри нормализованного
`SLOGGER_LOCAL_PROXY_TRACES_PATH` (по умолчанию `storage/slogger/traces`):

```text
<traces-path>/
├── local-proxy.lock
├── local-proxy-state.json
├── local-proxy.sock                    # единственный listener, имя фиксировано
├── local-proxy-errors-2026_11_17.logs  # daily error log
├── 2026_11_17_12
├── 2026_11_17_13
└── ...
```

- Имя hourly-файла — ровно `time.Now().Format("2006_01_02_15")` в локальной timezone процесса.
- Каталог создаётся рекурсивно с `0700`, файлы — `0600`; после bind Unix socket ему также
  выставляется `0600`. Существующие более широкие права не молча «чиним» при каждом запуске,
  а предупреждение о доступе к чувствительным данным описываем в документации.
- Lock, state, socket и error-логи не считаются trace-файлами. Trace cleanup перебирает только
  имена, полностью подходящие под `^\d{4}_\d{2}_\d{2}_\d{2}$`, и не трогает метаданные, логи
  или посторонние файлы.

## 5. Durable hourly storage

### 5.1. Формат записи

Каждый входной request сохраняется как отдельная запись без преобразования payload:

```text
[4 байта: big-endian размер JSON][исходный JSON payload]
```

Префикс на диске повторяет wire framing, но является частью файлового формата. Ответы `ok` и
`received` на диск не пишутся. Auth-frame тоже не пишется.

Причины хранить исходный payload:

- прокси не зависит от схемы полей трейса и её будущих расширений;
- повторная сериализация не меняет числа, escaping и неизвестные поля;
- sender может читать границы записей и восстанавливать неполный хвост.

Максимальный размер одного frame — 64 MiB, нулевая длина запрещена. Это защита памяти и
протокольная валидация, а не проверка содержимого/авторизации. На старте дополнительно не
валидируем каждый trace.

### 5.2. Writer и ротация

- Все handlers отправляют полностью прочитанные payload в buffered channel единственному writer.
- Writer открывает текущий файл с `O_CREATE|O_WRONLY|O_APPEND`, сериализует порядок записей и
  отвечает каждому handler результатом через отдельный result channel.
- Допустим group commit: writer может забрать уже накопившиеся запросы из channel, дописать их
  последовательно и сделать один `Sync` на группу. До завершения `Sync` никому из группы не
  отправляется успех.
- Перед первой записью после смены часа закрыть/`Sync` прежний файл и открыть новый. Сравнение
  имени делается на каждую группу записей, а не только по таймеру.
- Ошибка write/sync/close — fatal для процесса: нельзя продолжать принимать данные поверх
  неизвестного состояния файла. Supervisor перезапустит процесс; клиенты не получат ложный ack.
- После успешного append writer уведомляет sender неблокирующим signal channel (capacity 1),
  чтобы sender не поллил активный файл постоянно.

### 5.3. Восстановление после crash

До запуска writer проверить текущий hourly-файл; reader аналогично проверяет файл, который
открывает впервые:

- неполный 4-байтовый префикс в самом конце — обрезать до последней полной записи;
- префикс есть, payload короче объявленного в самом конце — обрезать до начала этой записи;
- длина больше лимита, нулевая длина или повреждение не в конце — остановить процесс с именем
  файла и offset;
- активный файл во время обычной работы reader не обрезает: временно неполный хвост означает
  «писатель ещё пишет», нужно ждать. Truncate разрешён только в startup recovery до запуска
  writer либо для уже закрытого файла.

Все offset — байтовые, от начала файла. `Seek` и чтение всегда идут с сохранённого offset.

### 5.4. Персистентный указатель

`local-proxy-state.json` содержит версию схемы и позицию sender:

```json
{
  "version": 1,
  "file": "2026_11_17_13",
  "offset": 12345,
  "completed": false
}
```

- `completed=false`: файл читается с `offset`.
- `completed=true`: закрытый часовой файл полностью отправлен, осталось безопасно удалить его и
  перейти к следующему.
- Пустое состояние означает выбор самого старого trace-файла с offset 0.
- Отсутствующий/пустой state при наличии файлов означает повторную отправку с начала, а не потерю.
- Каждое обновление state: записать временный файл в том же каталоге, `Sync`, `Rename`, затем
  `fsync` каталога. Никакого обновления state «in place».
- Неизвестная версия state, offset за EOF и malformed JSON — fatal, а не автоматический сброс.

Переход полностью отправленного файла:

1. sender достиг EOF закрытого файла;
2. атомарно сохраняет `completed=true` с конечным offset;
3. удаляет файл;
4. атомарно переключает state на следующий файл/пустое состояние.

При crash после любого шага восстановление однозначно: `completed=true` запрещает повторную
отправку, даже если сам файл уже удалён. Активный файл через этот переход не проходит.

## 6. Socket server: локальная часть

На каждое принятое Unix domain socket-соединение запускается ограниченная по времени жизни
handler-горутина:

1. Прочитать первый frame как auth handshake. JSON можно разобрать только для проверки формы,
  но значение `t` не сравнивается ни с чем и нигде не логируется.
2. Ответить `ok` в том же 4-байтовом framing.
3. В цикле читать очередной trace-frame, передавать writer и ждать durable-результат.
4. Только после успеха ответить `received`; при ошибке закрыть соединение без ложного ack.

Требования:

- Поддержать постоянное соединение и несколько одновременных PHP queue workers.
- Использовать `io.ReadFull`/точные writes, корректно обрабатывать короткие чтения и записи.
- Read/write deadlines не дают зависшему клиенту навсегда занять goroutine. После успешного
  exchange deadline продлевается; idle timeout не должен быть короче типичного перерыва между
  пачками. Допустимо не держать deadline между полноценными idle-периодами, но ограничить чтение
  уже начатого frame.
- На malformed/oversized frame закрыть только это соединение и записать rate-limited warning без
  payload и токена.
- Не отправлять в ответ произвольный текст ошибки как будто это ack: PHP ожидает строго `received`.

## 7. Sender: отправка в настоящий SLogger

### 7.1. Upstream protocol

- Создать отдельное TCP-соединение, отправить `{"t": SLOGGER_TOKEN}`, дождаться `ok`.
- Для каждой объединённой пачки отправить JSON и ждать строго `received`.
- Неустранимый ответ, EOF, timeout и ошибка записи закрывают соединение; следующая попытка
  начинается с нового handshake.
- Токен и payload не логировать. Логировать адрес, класс ошибки, размер/число операций и offset.
- На каждой попытке Go-прокси к серверу SLogger действуют connect timeout 10 секунд,
  write deadline 20 секунд и read deadline 20 секунд.
  Любая ошибка connect/auth/write/read, EOF, timeout или ответ, отличный от `ok`/`received`, закрывает
  соединение и создаёт error-запись в текущем daily error-файле (и stderr).
- После ошибки та же пачка повторяется бесконечно ровно через **3 секунды** с нового handshake.
  Backoff progression и jitter нет. Offset не меняется. Трёхсекундный timer запускается после
  закрытия соединения/записи ошибки и прерывается контекстом shutdown; после успешной отправки
  sender сразу переходит к следующей пачке.

### 7.2. Размер пачки

Стартовые константы:

- максимум **200 create/update-операций**;
- максимум **4 MiB итогового JSON**;
- если накоплено меньше 200 операций и лимит 4 MiB не достигнут, отправка выполняется по
  секундному таймеру — **не чаще одного раза в секунду**;
- пачка из 200 операций отправляется сразу; если следующая операция уже не помещается в 4 MiB,
  текущая непустая пачка также отправляется сразу, не дожидаясь таймера. Пустой таймер никаких
  запросов не создаёт.

Исходный record не является неделимой пачкой: если в нём больше 200 операций или он не
помещается в 4 MiB, sender делит его массивы `c`/`u` по границам отдельных операций. Offset за
record продвигается только после подтверждения всех его частей; при restart уже отправленные
части могут повториться по общему правилу at-least-once. Перед добавлением каждой операции
проверяется точный размер сериализованного envelope, чтобы итоговый JSON не превышал 4 MiB.
Если одна отдельная операция в минимальном envelope всё равно больше 4 MiB, превышать лимит или
молча дропать её нельзя: sender останавливается с понятной ошибкой, offset и файл сохраняются для
ручного разбора.

Для объединения:

1. разобрать только внешний объект и внутренние массивы `c`/`u` как `json.RawMessage`;
2. не разбирать отдельные трейсы в Go-structs, чтобы не потерять неизвестные поля;
3. заново закодировать `c` и `u` именно как JSON-строки массивов, как ждёт backend;
4. сохранить порядок операций внутри каждого исходного record.

Важно: протокол всегда помещает все `c` перед всеми `u`, поэтому нельзя бездумно объединить
произвольные record и поменять глобальный порядок. Объединять только совместимый префикс: после
первого update не добавлять в ту же пачку более поздний create; в этой точке сначала отправить
накопленное. Так create/update из разных исходных запросов не меняются местами. Тест обязан
покрыть последовательности `c,u`, `u,c` и смешанные record.

### 7.3. Чтение и удаление

- Файлы обрабатываются по возрастанию имени, начиная с state; более старые невыполненные файлы
  не перескакиваются.
- После `received` атомарно сохранить новый offset; только затем читать следующую пачку.
- Для активного файла EOF означает ожидание writer notification или смены часа.
- Для закрытого файла EOF запускает `completed -> delete -> next` из § 5.4.
- При штатной работе sender один; cleanup выполняется в том же control loop, поэтому удаление по
  retention не конкурирует с чтением/сохранением offset.

## 8. Retention и error-логи

### 8.1. Trace-файлы

- Cleanup запускается сразу при старте и затем каждые 10 минут через `time.Ticker`.
- Возраст считается от часа в имени файла, не от mtime: файл `2026_11_17_13` истекает, когда
  `now >= parsedHour + retentionHours`.
- Активный текущий час не удаляется даже при ошибочно маленьком retention.
- Полностью отправленные файлы удаляются сразу по § 7.3, не дожидаясь retention.
- Просроченные неотправленные файлы удаляются тоже — это ограничение диска, явно заданное
  оператором. Для каждого такого удаления писать warning с именем и причиной.
- Если cleanup удалил файл, на который указывает state, sender атомарно переходит к следующему
  существующему файлу. `completed` здесь не выставляется: файл не отправлен, а expired.
- Ошибки чтения каталога/удаления логируются и повторяются на следующем цикле; посторонние и
  временные state-файлы не затрагиваются.

В README отдельно указать: retention — это преднамеренная потеря старых неотправленных трейсов,
и при длительном outage его надо увеличить.

### 8.2. Daily error-логи

- Каждая error-запись пишется в `<traces-path>/local-proxy-errors-YYYY_MM_DD.logs`, где дата —
  `time.Now().Format("2006_01_02")` в локальной timezone. Пример:
  `local-proxy-errors-2026_11_17.logs`.
- Файл открывается в append mode с `0600`; запись — одна строка structured JSON (`slog`). В него
  попадают ошибки приёма/записи, upstream connect/auth/read/write/timeout, неожиданные ответы,
  recovery и cleanup. Payload, auth token и содержимое трейсов не логируются.
- Logger сам следит за сменой локальных суток при следующей error-записи: закрывает прежний файл и
  открывает новый. Доступ к текущему файлу сериализуется, чтобы записи goroutine не перемешивались.
- Error-логи очищаются тем же startup/10-минутным cleanup. Файл считается expired, когда
  `now >= localDayStart.AddDate(0, 0, 3)`: хранятся максимум текущий и два предыдущих календарных
  дня, то есть три суточных файла. Учитываются только имена, полностью соответствующие
  `^local-proxy-errors-\d{4}_\d{2}_\d{2}\.logs$`.
- Текущий daily-файл не удаляется. Посторонние `.logs` и файлы с malformed-датой не трогаются.
- Ошибка записи в error-файл выводится в stderr без рекурсивного логирования и не меняет offset или
  ack семантику очереди.

## 9. Жизненный цикл процесса

### 9.1. Защита от двойного запуска

- Открыть `local-proxy.lock` и взять неблокирующий `flock(LOCK_EX|LOCK_NB)` до bind listener.
- Держать file descriptor открытым весь срок жизни процесса; unlock при выходе.
- Lock-файл не удалять при shutdown: удаление открытого inode создаёт окно, в котором два процесса
  могут залочить разные файлы с одним путём.
- Для Unix listener stale socket удаляется только после успешного flock. Перед удалением `Lstat`
  обязан подтвердить, что по строго заданному пути находится socket, а не обычный файл/symlink;
  после bind выполняется `chmod 0600`. Это безопасно восстанавливает запуск после `kill -9`.
- Второй запуск завершается с ненулевым кодом и сообщением, что прокси уже запущен.
- Реализация Linux-specific через `syscall.Flock`; Windows в этой задаче не поддерживается.

### 9.2. Graceful shutdown

Обрабатывать `SIGINT` и `SIGTERM` через `signal.NotifyContext`:

1. прекратить accept новых соединений, закрыть listener;
2. прекратить запуск новых upstream-попыток и cleanup;
3. дать уже принятым записям завершить `write + fsync` и отправить ack, а sender — завершить
   текущий exchange, если он укладывается в общий дедлайн;
4. закрыть текущий hourly-файл, upstream и оставшиеся соединения;
5. дождаться всех goroutine через `WaitGroup`, удалить только принадлежащий этому процессу
   `local-proxy.sock` и после этого отпустить lock.

Общий forced timeout от сигнала — ровно 5 секунд. По его истечении принудительно закрыть
соединения, чтобы сетевые read/write не блокировали выход, дождаться завершения и выйти. Второй
сигнал может завершать процесс немедленно. Незавершённые записи/offset восстанавливаются по
правилам at-least-once после перезапуска.

Fatal internal error тоже отменяет общий context: процесс завершается, Supervisor делает restart.
Не оставлять «живой» listener после смерти writer или sender.

## 10. Artisan-команда установки

Новый класс: `src/Commands/DownloadLocalProxyCommand.php`.

Предлагаемая сигнатура:

```bash
php artisan slogger:local-proxy:download \
    [--release=] [--path=] [--force]
```

Поведение:

- По умолчанию версия — установленная версия `slogger/laravel` через
  `Composer\InstalledVersions`; для source-checkout fallback — `composer.json -> version`.
- Путь по умолчанию — `base_path('vendor/bin/slogger-local-proxy')`; `--path` нужен также для
  изолированных тестов. Каталог создаётся при необходимости.
- Поддерживается только Linux amd64: проверить `PHP_OS_FAMILY === 'Linux'` и соответствие
  `php_uname('m')` (`x86_64`/`amd64`). Остальные OS/arch завершаются понятной ошибкой без
  попытки скачивания.
- Скачать по прямому URL GitHub Release, без зависимости от лимитов GitHub API:
  `https://github.com/sprust/slogger-laravel/releases/download/v<version>/<asset>`.
- Скачать общий checksums-файл и проверить SHA-256 выбранного asset через `hash_equals`.
- Писать во временный файл рядом с destination, проверить размер/ошибку HTTP, выставить `0755` и
  атомарно переименовать. Частичный download не должен заменять рабочий бинарь.
- Без `--force` существующий destination не перезаписывать. Для обновления документировать
  команду с `--force` и последующий restart Supervisor.
- Команда регистрируется в `ServiceProvider::registerConsole()` независимо от
  `SLOGGER_ENABLED`: установить прокси должно быть можно до включения трейсинга.
- Бинарь не коммитить и не добавлять в Composer distribution; `vendor/` уже игнорируется git.

## 11. CI и релиз

Изменить `.github/workflows/release.yml`, сохранив текущую PHP-матрицу и неизменяемость тегов.

### 11.1. Проверки

Новый отдельный job `proxy-tests`:

- checkout, `actions/setup-go` по `local-proxy/go.mod`;
- проверка пустого `gofmt -l`;
- `go vet ./...`;
- `go test -race ./...`;
- обычный `go build ./cmd/slogger-local-proxy`.

Добавить `local-proxy/**` в push paths, иначе изменение Go-кода не запустит workflow и не попадёт
в релиз. Workflow по-прежнему запускается на каждый non-draft PR в `master`.

Отдельный integration job строит Linux-бинарь, устанавливает PHP dependencies и запускает PHPUnit
тест реального PHP `SocketClient -> Go proxy -> fake upstream`. Release обязан зависеть и от
Go/integration checks, а не только от PHP matrix.

### 11.2. Linux build и assets

Для release-события собрать только Linux amd64 с `GOOS=linux`, `GOARCH=amd64`, `CGO_ENABLED=0`,
`-trimpath` и версией через `-ldflags`:

```text
slogger-local-proxy_linux_amd64
slogger-local-proxy_checksums.txt
```

Checksums содержит SHA-256 единственного бинаря. Других OS и архитектур в релизе пока нет.

Собранные binary и checksums создаются непосредственно в release job после разрешения версии.
Существующий `gh release create` получает оба assets в том же вызове.
Если тег уже существует, сохраняется текущий guard: релиз и assets повторно не перезаписываются.
Таймаут release job увеличить с запасом на build/upload.

Версия бинаря обязана совпадать с `composer.json`: `slogger-local-proxy -version` печатает
`vX.Y.Z`, что позволяет проверить установку.

### 11.3. Локальные команды

Добавить в `makefile`:

- `make proxy-check` — gofmt/vet/race tests/build через `docker-compose exec php`;
- `make proxy-build` — Linux amd64 build в том же PHP-контейнере во временный/игнорируемый
  output-каталог, не в `vendor/bin` репозитория;
- `make proxy-integration` — build и запуск PHP integration test там же, с явным путём к бинарю.

Go toolchain устанавливается в существующий `docker/php/Dockerfile`: pinned Go toolchain можно
скопировать из официального Go image на build-stage либо установить из проверенного архива, затем
добавить `/usr/local/go/bin` в `PATH`. Отдельный runtime/service-контейнер для Go не создаётся;
все `make proxy-*` используют текущий `php` service. Версия toolchain фиксируется и согласуется с
`local-proxy/go.mod`.

Финальная проверка проекта: `make check`, `make proxy-check`, `make proxy-integration`,
`make check-laravel-all` для PHP-изменений.

## 12. Тесты

### 12.1. Go unit tests

**Protocol/server**

- короткие/фрагментированные reads и writes, несколько пачек на одном соединении;
- handshake всегда получает `ok` при любом/пустом token;
- нулевой/oversized/truncated frame не приводит к ack и не съедает память;
- handshake и серия пачек работают через фиксированный Unix domain socket;
- несколько одновременных клиентов не перемешивают записи;
- ack приходит только после подтверждённого writer `Sync`.

**Storage**

- имя и ротация по injected clock, включая границу часа;
- append нескольких record и точные offsets;
- восстановление неполного префикса/payload только в хвосте;
- malformed середина, слишком большая длина, offset за EOF — ошибка, не silent reset;
- атомарное state recovery при отсутствии state, `completed=true` до/после удаления файла;
- concurrent writers/race detector не обнаруживают гонок.

**Batch/sender**

- объединение двойно закодированных `c`/`u` без потери неизвестных полей;
- лимиты 200 операций/4 MiB, разбиение oversized record по операциям и ошибка на одной операции,
  которая сама больше 4 MiB;
- полная пачка отправляется сразу, неполная — не чаще одного раза в секунду, пустой таймер ничего
  не отправляет;
- запрет инверсии `u -> c` при объединении;
- отсутствие `received`, неожиданный ответ, EOF и timeout не продвигают offset;
- для upstream Go → SLogger connect timeout равен 10 секундам, write timeout — 20 секундам,
  read timeout — 20 секундам; после любой ошибки повтор
  той же пачки происходит через ровно 3 секунды, создаётся новая error-запись и новый handshake;
- после reconnect успешная отправка продвигает offset ровно один раз;
- EOF активного файла ждёт, EOF закрытого удаляет через durable state;
- retention считает возраст по имени, не трогает active/посторонние файлы и корректно двигает state;
- restart sender продолжает с offset; crash после upstream ack допускает только дубликат, не потерю.

**Config/logging**

- `SLOGGER_LOCAL_PROXY_TRACES_PATH` нормализует все четыре формы с ведущими/завершающими слешами к
  одному относительному пути; пустой путь, `.` и `..` отклоняются;
- daily error-файл имеет точное имя `local-proxy-errors-YYYY_MM_DD.logs`, права `0600`, append и
  ротацию на границе локальных суток;
- cleanup оставляет текущий и два предыдущих дня, удаляет третий expired daily-файл и не трогает
  malformed/посторонние файлы;
- error-записи не содержат token/payload и продолжают писаться при upstream retry каждые 3 секунды.

### 12.2. Go process tests

- второй процесс не может взять lock и не заменяет `local-proxy.sock`;
- stale `local-proxy.sock` после kill -9 безопасно удаляется, обычный файл по этому пути не
  удаляется; при graceful shutdown socket исчезает, а его права при работе равны `0600`;
- `SIGTERM` закрывает listener и завершает процесс не позднее 5 секунд;
- restart с теми же файлами не теряет подтверждённые записи;
- upstream down -> накопление -> upstream up -> автоматическая доставка;
- kill -9 во время append/read с последующим recovery (интеграционный сценарий, не unit test).

### 12.3. PHP tests

Новые тесты команды, выбора адреса и конфигурации:

- по умолчанию `direct` использует прежний `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL`;
- `proxy` возвращает `unix://` + `base_path($normalizedTracesPath . '/local-proxy.sock')` и не
  использует постоянный SLogger URL как локальный адрес;
- `/storage/slogger/traces/` и `storage/slogger/traces` дают одинаковый socket path; ведущий `/`
  не превращает настройку в filesystem-absolute path, а `..`/пустой путь отклоняются;
- неизвестный `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE` вызывает понятную ошибку;
- command доступна при `SLOGGER_ENABLED=false`;
- правильный выбор единственного Linux amd64 asset и URL версии;
- успешная установка, executable mode и `--force`;
- HTTP error, неверный checksum и прерванный download оставляют прежний бинарь нетронутым;
- неподдерживаемая платформа и неизвестная версия дают понятный failure;
- новые config defaults читаются из env.

Существующие тесты socket-клиента не переписывать под прокси: его wire protocol не меняется.

### 12.4. Сквозная совместимость

`tests/Feature/LocalProxy/LocalProxyIntegrationTest.php` запускается только когда задан путь к
собранному бинарю; в обычной PHP-матрице без Go он явно skipped, в integration CI обязателен.

Сценарий:

1. поднять fake upstream, который проверяет настоящий token и сохраняет принятые payloads;
2. запустить настоящий Go-бинарь с temp `.env`, рабочим каталогом temp-приложения и
   `SLOGGER_LOCAL_PROXY_TRACES_PATH=/storage/slogger/traces/`; его единственный listener должен
   появиться по пути `<temp>/storage/slogger/traces/local-proxy.sock`;
3. при `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE=proxy` создать клиент через настоящий
   `ApiClientFactory` и отправить несколько create/update пачек; SLogger URL при этом указывает на
   fake upstream для Go, а PHP всё равно подключается только к Unix socket;
4. проверить `ok`/`received`, содержимое upstream, auth upstream и отсутствие проверки local auth;
5. перезапустить прокси и upstream, отправить ещё и проверить продолжение без потерь;
6. проверить hourly files, persisted offset, удаление полностью отправленного закрытого файла,
   flock, SIGTERM и запись upstream-ошибки в `local-proxy-errors-{DAY}.logs`.

Для часовых границ и retention основные проверки остаются в Go с injected clock; интеграционный
тест не должен ждать реальный час или 10 минут.

## 13. Документация

Обновить синхронно `README.md` и `README.ru.md`:

1. **Local proxy: назначение** — локальная durable-буферизация при недоступности SLogger,
   at-least-once и возможные дубликаты.
2. **Установка** — `php artisan slogger:local-proxy:download`, поддержка только Linux amd64,
   `vendor/bin/slogger-local-proxy -version`, обновление через `--force`.
3. **Конфигурация** — переключение `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE=direct|proxy`,
   постоянный `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL` для direct PHP и upstream Go,
   `SLOGGER_LOCAL_PROXY_TRACES_PATH` и retention. Отдельно показать, что `/storage/slogger/traces/`
   и `storage/slogger/traces` эквивалентны, задают путь относительно корня проекта, а имя
   `local-proxy.sock` остаётся фиксированным.
4. **Supervisor** — пример с `directory` в корень приложения, абсолютным `-env`,
   `autostart=true`, `autorestart=true`, `stopsignal=TERM`, `stopwaitsecs=10`, отдельным
   не-root user и доступом на настроенный каталог трейсов.
5. **Перезапуск/обновление** — `supervisorctl restart`, безопасность повторного запуска и flock.
6. **Диск и восстановление** — имена hourly-файлов, persisted state, удаление после отправки,
   cleanup каждые 10 минут, права и чувствительность данных.
7. **Ошибки и retries** — daily `local-proxy-errors-{DAY}.logs` в каталоге трейсов, хранение три
   дня, connect/read/write timeouts, повтор одной и той же пачки через 3 секунды с error-записью.
8. **Troubleshooting** — upstream down, auth failed, lock уже занят, malformed state/file, нехватка
   места, путь к daily error-логам и Supervisor stdout/stderr.
9. **Откат** — установить `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE=direct` и остановить
   прокси; прежний SLogger URL не меняется, а оставшиеся файлы не должны молча исчезнуть при смене
   типа клиента.
10. **Разработка** — `make proxy-check`, `make proxy-integration`, release assets.

В `.env.example` добавить `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE=direct`,
`SLOGGER_LOCAL_PROXY_TRACES_PATH=storage/slogger/traces` и
`SLOGGER_LOCAL_PROXY_RETENTION_HOURS` с безопасными defaults. В комментарии к пути прямо указать:
«каталог относительно корня проекта; `/storage/slogger/traces/` и `storage/slogger/traces`
эквивалентны, ведущие и завершающие слеши игнорируются». Отдельный комментарий к URL: его используют
direct PHP и Go-прокси, при смене типа он не перезаписывается. В release notes/upgrading указать,
что для включения нужны `client type=proxy`, скачанный бинарь и Supervisor; обычное поведение без
прокси не меняется.

## 14. Этапы реализации и коммиты

Каждый этап завершается зелёными релевантными проверками.

1. **Go module, protocol и storage.** Конфиг, framing, hourly writer, recovery, durable state,
   unit tests. Ещё нет публичного включения.
2. **Server и sender.** Локальный handshake, append ack, batching, upstream retries, rotation,
   deletion/retention, notification между writer/sender.
3. **Жизненный цикл.** Flock, общий context, 5-секундный shutdown, structured logs, process tests
   и restart/recovery сценарии.
4. **Laravel integration.** Config/env, Artisan download command, provider registration, PHP unit
   tests и реальный PHP-to-Go integration test.
5. **Build/release.** PHP container toolchain, Makefile, Go CI checks, Linux amd64 build, checksums,
   release assets, обязательные path filters и job dependencies.
6. **Документация и финальная приёмка.** Оба README, `.env.example`, Supervisor example,
   troubleshooting, полный набор проверок и ручной smoke на собранном release-equivalent binary.

## 15. Приёмка

Задача считается реализованной, когда:

- [ ] При `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TYPE=proxy` PHP-клиент выбирает
  `local-proxy.sock` внутри `SLOGGER_LOCAL_PROXY_TRACES_PATH`, успешно проходит handshake и получает
  `received` после durable append. Записи `/storage/slogger/traces/` и `storage/slogger/traces`
  нормализуются к одному каталогу относительно корня проекта.
- [ ] При `direct` сохраняется прежнее подключение по
  `SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL`; при `proxy` Go читает тот же URL как upstream.
- [ ] Пачка содержит максимум 200 операций и занимает не больше 4 MiB; полная отправляется сразу,
  неполная — не чаще одного раза в секунду.
- [ ] Payload до upstream доходит без потери полей; несколько малых пачек объединяются до заданных
  лимитов без create/update инверсии.
- [ ] При выключенном upstream данные остаются на диске; connect/read/write ограничены таймаутами,
  каждая ошибка попадает в daily error-лог, а повтор той же пачки выполняется через 3 секунды.
  После включения upstream доставка продолжается без ручного вмешательства.
- [ ] После обычного restart и kill -9 сохраняются все подтверждённые записи; допустимы только
  объяснимые at-least-once дубликаты.
- [ ] Полностью отправленный закрытый файл удаляется; cleanup запускается раз в 10 минут и
  удаляет expired-файлы по `SLOGGER_LOCAL_PROXY_RETENTION_HOURS`.
- [ ] Ошибки пишутся в `local-proxy-errors-YYYY_MM_DD.logs` в каталоге трейсов; daily-файлы
  ротируются и хранятся не более трёх дней, token/payload в них отсутствуют.
- [ ] Второй процесс не стартует; `SIGTERM` завершает первый не более чем за 5 секунд.
- [ ] Artisan скачивает asset своей версии в `vendor/bin`, проверяет checksum и делает его
  исполняемым.
- [ ] GitHub Release содержит Linux amd64-бинарь и checksums, а версия бинаря совпадает с Composer
  release.
- [ ] `make check`, `make proxy-check`, `make proxy-integration` и PHP version matrix проходят.
- [ ] Оба README содержат установку, настройку пути с обеими формами слешей, Supervisor, retention,
  daily error-логи, retry и troubleshooting.

## 16. Осознанные ограничения

- Нет exactly-once и дедупликации на прокси.
- Нет проверки локального token/TLS: единственный локальный listener — фиксированный Unix socket
  с правами `0600` в каталоге трейсов.
- Нет TCP listener и настройки имени локального socket; его каталог следует из configured traces
  path, а upstream прокси остаётся TCP.
- Собирается только Linux amd64; HTTP API и собственный UI отсутствуют.
- Нет немедленного удаления активного файла на EOF: это было бы гонкой с будущими append.
- Retention может удалить неотправленные трейсы; это документированный механизм ограничения диска,
  а не сбой доставки.
- Не меняются PHP `SocketClient`, формат его payload и поведение приложений, не включивших proxy.
