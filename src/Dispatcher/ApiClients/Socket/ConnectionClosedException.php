<?php

declare(strict_types=1);

namespace SLoggerLaravel\Dispatcher\ApiClients\Socket;

use RuntimeException;

/**
 * The peer has closed the connection.
 *
 * Must stay distinguishable from a timeout: "reconnect and retry" and
 * "the server is slow under load" call for opposite reactions.
 */
class ConnectionClosedException extends RuntimeException
{
}
