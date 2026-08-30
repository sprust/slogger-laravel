<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\ApiClients;

use PHPUnit\Framework\TestCase;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\Connection;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\ConnectionPool;

class ConnectionPoolTest extends TestCase
{
    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->made = 0;
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

    private function makePool(): ConnectionPool
    {
        return new ConnectionPool(
            function (): Connection {
                $this->made++;

                return $this->createMock(Connection::class);
            }
        );
    }
}
