<?php

declare(strict_types=1);

namespace SLoggerLaravel\Dispatcher\ApiClients\Socket;

use Closure;

/**
 * Connections nobody is sending on, to be taken one at a time.
 *
 * Sending a batch is one write followed by one read on a length-prefixed stream, so it
 * has to have the stream to itself from end to end. Where the trace queue is served by
 * a pool of coroutine consumers, several batches are on their way at once: sharing a
 * single connection puts two frames on the wire interleaved and a sender reads the
 * answer to somebody else's.
 *
 * A sender takes a connection and gives it back, so a process that sends one batch at a
 * time opens exactly one and reuses it for good - the persistent connection this always
 * had. Concurrent senders each get one of their own, and however many the peak needed
 * stay here to be reused rather than reconnected.
 *
 * Nothing about this is aware of fibers, which is the point: it holds for any way of
 * running things at once.
 */
class ConnectionPool
{
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
        $this->idle[] = $connection;
    }
}
