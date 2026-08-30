<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\ApiClients;

use PHPUnit\Framework\TestCase;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\Connection;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\ConnectionPool;

class ConnectionPoolTest extends TestCase
{
    private int $made = 0;

    private int $closed = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->made   = 0;
        $this->closed = 0;
    }

    /**
     * One sender at a time opens one connection and keeps it: the persistent
     * connection per process this had before there was a pool.
     */
    public function testAConnectionGivenBackIsHandedOutAgain(): void
    {
        $pool = $this->makePool();

        for ($i = 0; $i < 5; $i++) {
            $pool->release($pool->acquire());
        }

        self::assertSame(1, $this->made);
    }

    public function testASenderHoldingOneDoesNotGetItAgain(): void
    {
        $pool = $this->makePool();

        $first  = $pool->acquire();
        $second = $pool->acquire();

        self::assertNotSame($first, $second);
        self::assertSame(2, $this->made);
    }

    /**
     * However many the peak needed stay to be reused, rather than being reconnected.
     */
    public function testThePeakIsKeptAndReused(): void
    {
        $pool = $this->makePool();

        $held = [$pool->acquire(), $pool->acquire(), $pool->acquire()];

        foreach ($held as $connection) {
            $pool->release($connection);
        }

        self::assertSame(3, $this->made);

        for ($i = 0; $i < 3; $i++) {
            $pool->release($pool->acquire());
        }

        self::assertSame(3, $this->made, 'nothing new was opened for a load already seen');
    }

    /**
     * A burst wider than the pool keeps is served, and what it opened beyond that is
     * closed rather than held open for the rest of the process.
     */
    public function testABurstIsServedAndThenGivenBack(): void
    {
        $pool = $this->makePool();

        $held = [];

        for ($i = 0; $i < 12; $i++) {
            $held[] = $pool->acquire();
        }

        self::assertSame(12, $this->made, 'every sender got one of its own');

        foreach ($held as $connection) {
            $pool->release($connection);
        }

        self::assertSame(4, $this->closed, 'the ones past what is kept were closed');

        // and the kept ones are handed out again rather than reconnected
        for ($i = 0; $i < 8; $i++) {
            $pool->acquire();
        }

        self::assertSame(12, $this->made);
    }

    private function makePool(): ConnectionPool
    {
        return new ConnectionPool(
            function (): Connection {
                $this->made++;

                $connection = $this->createMock(Connection::class);

                $connection->method('disconnect')->willReturnCallback(
                    function (): void {
                        $this->closed++;
                    }
                );

                return $connection;
            }
        );
    }
}
