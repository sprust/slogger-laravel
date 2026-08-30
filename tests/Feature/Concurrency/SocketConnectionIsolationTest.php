<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Concurrency;

use Fiber;
use Illuminate\Support\Carbon;
use JsonException;
use RuntimeException;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\Connection;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\SocketClient;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

/**
 * Sending a batch is one write followed by one read on a length-prefixed stream. The
 * trace queue is served by a pool of coroutine consumers, so several batches are on
 * their way at once: sharing a connection would put two frames on the wire interleaved
 * and a sender would read the answer to somebody else's.
 */
class SocketConnectionIsolationTest extends BaseTestCase
{
    /**
     * Connections that exist: the one the client was built with, plus every one it
     * asked that connection for.
     *
     * @var list<Connection>
     */
    private array $made = [];

    /**
     * What each write went to, in the order the writes happened.
     *
     * @var list<Connection>
     */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->made    = [];
        $this->written = [];
    }

    /**
     * @throws JsonException
     */
    public function testTwoSendersInFlightAtOnceDoNotShareAConnection(): void
    {
        $client = $this->makeClient();

        $send = function () use ($client): void {
            $client->sendTraces($this->makeTraces());
        };

        $first  = new Fiber($send);
        $second = new Fiber($send);

        // both suspended mid-write, each inside its own frame
        $first->start();
        $second->start();

        $first->resume();
        $second->resume();

        self::assertCount(2, $this->written);
        self::assertNotSame(
            $this->written[0],
            $this->written[1],
            'a sender holds its connection until its answer is read'
        );
    }

    /**
     * And what the concurrency cost: two connections for two senders at once, then
     * nothing new for the sends that follow.
     *
     * @throws JsonException
     */
    public function testTheConnectionsAreReusedOnceTheSendersAreDone(): void
    {
        $client = $this->makeClient();

        $send = function () use ($client): void {
            $client->sendTraces($this->makeTraces());
        };

        $first  = new Fiber($send);
        $second = new Fiber($send);

        $first->start();
        $second->start();
        $first->resume();
        $second->resume();

        self::assertCount(2, $this->made);

        // sequential from here on, as a worker process sends
        $client->sendTraces($this->makeTraces());
        $client->sendTraces($this->makeTraces());

        self::assertCount(2, $this->made, 'nothing reconnected for a load already seen');
    }

    /**
     * The write yields, which is what a coroutine runtime does to a socket and where a
     * shared stream would get crossed.
     */
    private function makeClient(): SocketClient
    {
        return new SocketClient(
            apiToken: 'token-1',
            connection: $this->makeConnection()
        );
    }

    /**
     * `fresh()` is how the client opens a second one: another connection to the same
     * receiver, which here is another mock behaving the same way.
     */
    private function makeConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('isConnected')->willReturn(true);

        $connection->method('write')->willReturnCallback(
            function () use ($connection): void {
                $this->written[] = $connection;

                if (Fiber::getCurrent()) {
                    Fiber::suspend();
                }
            }
        );

        $connection->method('read')->willReturn('received');

        $connection->method('fresh')->willReturnCallback(
            fn(): Connection => $this->makeConnection()
        );

        $this->made[] = $connection;

        return $connection;
    }

    private function makeTraces(): TracesObject
    {
        return (new TracesObject())->addCreating(
            new TraceCreateObject(
                traceId: 'trace-1',
                parentTraceId: null,
                type: 'request',
                status: 'started',
                tags: [],
                data: [],
                duration: null,
                memory: null,
                cpu: null,
                isParent: true,
                loggedAt: Carbon::create(2024, 1, 1, 0, 0, 0)
                    ?: throw new RuntimeException('Failed to create Carbon instance')
            )
        );
    }
}
