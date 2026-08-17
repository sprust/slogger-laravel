# Prompt: teach the slogger-laravel socket client to survive a closed connection

Target repository: `https://github.com/sprust/slogger-laravel` (installed here as
`slogger/laravel`). This file is a task brief — it belongs to the client package,
not to `slogger.back`. Everything below was verified against the code at
`857f5401628a492a4091498d6ccf2817c53c8fab`.

## Problem

Senders periodically fail with `Failed to read from socket by timeout`. The message
is misleading: in the common case the receiver has already closed the connection
and the client has no way to notice.

Reproduced deterministically against the Go receiver (`slogger.back`,
`servers/receiver`) before it was fixed:

```
1) connected, isConnected=true
2) first exchange: received
3) staying silent for 35 seconds
4) isConnected after the pause = true    <- client still believes it is connected
5) write did NOT throw                   <- so the reconnect fallback never fires
6) read threw after 5.00s: Failed to read from socket by timeout
```

Chain of events:

1. The peer closes the connection (receiver restart, deploy, network fault; until
   recently also a plain 30-second idle period).
2. `Connection::$connected` stays `true` — it is a local flag cleared only by an
   explicit `disconnect()`. `SocketClient::connectIfNeed()` trusts it and skips
   reconnecting.
3. `Connection::write()` does not throw: the first `fwrite()` into a socket the peer
   has closed lands in the local send buffer and reports success. The reconnect
   fallback in `SocketClient::sendTraces()` is wired to a `write()` exception only,
   so it never runs.
4. `Connection::read()` never checks `feof()`. A closed connection is
   indistinguishable from "no data yet", so it spins for `$timeoutSeconds` and
   reports a timeout.

The receiver side has been fixed separately (it no longer applies a read deadline to
idle connections), which removes the most frequent trigger. It does not remove the
class of failure: any restart, deploy or network fault still produces the same
misleading error.

## Changes

### 1. Detect a peer-closed connection in `Connection::read()`

`src/Dispatcher/ApiClients/Socket/Connection.php` has two identical wait loops — the
length prefix (around line 193) and the payload (around line 233). Both treat an
empty `fread()` as "not yet". Before falling into the timeout logic, check whether
the stream is at EOF:

```php
if (!$chunk) {
    if (feof($socket)) {
        $this->connected = false;

        throw new ConnectionClosedException('Connection closed by peer');
    }

    // existing timeout logic
}
```

Clearing `$this->connected` is the load-bearing part — without it
`connectIfNeed()` keeps reusing the dead connection.

### 2. Add `ConnectionClosedException`

New class in the same namespace, extending `RuntimeException`. It has to be
distinguishable from a timeout: "reconnect and retry" and "the server is slow under
load" call for opposite reactions. Extending `RuntimeException` keeps existing
`catch (Throwable)` / `catch (RuntimeException)` call sites working.

### 3. Apply the same check in `Connection::write()`

`fwrite()` into a closed socket succeeds once. Check `feof()` after the write and
raise `ConnectionClosedException` there too, so the break surfaces on the write that
caused it instead of on the next read.

### 4. Retry once in `SocketClient::sendTraces()`

The current fallback (around lines 76-84 of `SocketClient.php`) wraps `write()` only,
and `read()` sits outside it. Wrap the write/read pair together:

```php
try {
    $this->connection->write($payloadJson);

    $response = $this->connection->read();
} catch (ConnectionClosedException) {
    $this->connection->connect($this->apiToken);
    $this->connection->write($payloadJson);

    $response = $this->connection->read();
}
```

Exactly one retry, and only for `ConnectionClosedException`. Do not extend it to
timeouts — under load that turns into a reconnect storm against a receiver that is
already saturated.

### 5. Make the timeout configurable

`Connection::$timeoutSeconds` is hardcoded to `5` (line 26). Move it into the
constructor with a default of `10`:

```php
public function __construct(
    protected string $socketAddress,
    protected LoggerInterface $logger,
    protected int $timeoutSeconds = 10,
) {
}
```

Raising the default from 5 to 10 buys headroom on the overload path, where the
receiver's handler pool is full and the acknowledgement is genuinely late. It is not
a fix for that path — capacity is — but 5 seconds is tight enough to turn ordinary
load spikes into errors.

Wire it through so it can be set from config without patching the package:

- `config/slogger.php` — add `timeout_seconds` next to `url` under
  `dispatchers.queue.api_clients.socket`, read from
  `env('SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_TIMEOUT', 10)`.
- `src/Configs/DispatcherQueueConfig.php` — add `getSocketClientTimeoutSeconds(): int`
  alongside the existing `getSocketClientUrl()`.
- `src/Dispatcher/ApiClients/ApiClientFactory.php::createSocket()` — pass it into the
  `Connection` constructor.

Note that `stream_socket_client()` in `connect()` uses a separate hardcoded
`timeout: 2.0` for establishing the connection. Leave it alone unless there is a
reason to change it — it is a different concern from the read/write timeout.

## Constraints

- Public signatures must not change beyond the new optional constructor argument.
- No behaviour change on the happy path.
- The package targets the same PHP version as the rest of the project (8.4).

## Acceptance

The scenario below must return `received` rather than throwing. It is the reproducer
from the top of this file, with the pause replaced by a forced disconnect, since the
receiver no longer closes idle connections:

1. Connect and exchange one message successfully.
2. Restart the receiver (or otherwise force the peer to close).
3. Send another message.

Expected: the client notices the closed connection, reconnects transparently, and the
send succeeds. `Failed to read from socket by timeout` must not appear.

Worth adding as an automated test if the package has socket test infrastructure — that
was not checked when this brief was written, so confirm before assuming either way.
