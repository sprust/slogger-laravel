<?php

declare(strict_types=1);

namespace SLoggerLaravel\Dispatcher\ApiClients\Socket;

use Closure;

/**
 * Connections nobody is sending on, to be taken one at a time.
 *
 * Sending a batch is one write followed by one read on a length-prefixed stream, and
 * the protocol carries nothing to say whose reply is whose - so a sender has to have
 * the stream to itself from end to end.
 *
 * Whether two senders can get inside each other's exchange depends on the runtime, not
 * on this package: a bare PHP fiber suspends only where it says so, and `Connection`
 * says so nowhere. A runtime that turns a stream call into a suspension point - which
 * is what makes coroutines worth having - can, and then a shared connection gets its
 * frames torn and its answers crossed.
 *
 * A sender takes a connection and gives it back, so a process that sends one batch at a
 * time opens exactly one and reuses it for good - the persistent connection this always
 * had. Concurrent senders each get one of their own.
 *
 * Nothing about this is aware of fibers, which is the point: it holds for any way of
 * running things at once.
 */
class ConnectionPool
{
    /**
     * How many are kept for reuse. A burst wider than this is served - `acquire()`
     * always answers - but what it opened beyond this is closed on the way back
     * instead of being held open for the rest of the process.
     */
    private const MAX_IDLE = 8;

    /**
     * @var list<Connection>
     */
    private array $idle = [];

    /**
     * @param Closure(): Connection $factory
     */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function acquire(): Connection
    {
        return array_pop($this->idle) ?? ($this->factory)();
    }

    /**
     * Taken back whatever happened to it. A connection dropped mid-exchange comes back
     * disconnected and the next sender reconnects it, which is cheaper than the peer
     * closing an idle one nobody kept.
     */
    public function release(Connection $connection): void
    {
        if (count($this->idle) >= self::MAX_IDLE) {
            $connection->disconnect();

            return;
        }

        $this->idle[] = $connection;
    }
}
