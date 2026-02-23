<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\ApiClients;

use LogicException;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\Connection;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class ConnectionTest extends BaseTestCase
{
    public function testWriteSendsLengthPrefixedPayload(): void
    {
        $socketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        self::assertNotFalse($socketPair);

        [$left, $right] = $socketPair;

        $connection = new Connection('local', new NullLogger());
        $this->setConnectedSocket($connection, $left);

        $connection->write('payload');

        $header = fread($right, 4);

        self::assertNotFalse($header);

        /** @var int<1, max> $length */
        $length = (int) (unpack('N', $header)[1] ?? 0);

        $data = fread($right, $length);

        self::assertSame(7, $length);
        self::assertSame('payload', $data);
    }

    public function testReadReceivesLengthPrefixedPayload(): void
    {
        $socketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        self::assertNotFalse($socketPair);

        [$left, $right] = $socketPair;

        $connection = new Connection('local', new NullLogger());
        $this->setConnectedSocket($connection, $left);

        $payload = 'received';
        $buffer  = pack('N', strlen($payload)) . $payload;
        fwrite($right, $buffer);

        self::assertSame('received', $connection->read());
    }

    public function testReadThrowsOnEmptyResponse(): void
    {
        $socketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        self::assertNotFalse($socketPair);

        [$left, $right] = $socketPair;

        $connection = new Connection('local', new NullLogger());
        $this->setConnectedSocket($connection, $left);

        fwrite($right, pack('N', 0));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Empty response');

        $connection->read();

        fclose($left);
        fclose($right);
    }

    public function testReadThrowsWhenNotConnected(): void
    {
        $connection = new Connection('local', new NullLogger());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Socket is not connected');

        $connection->read();
    }

    /**
     * @param resource $socket
     */
    private function setConnectedSocket(Connection $connection, mixed $socket): void
    {
        $reflection = new ReflectionClass($connection);

        $socketProperty = $reflection->getProperty('socket');
        $socketProperty->setAccessible(true);
        $socketProperty->setValue($connection, $socket);

        $connectedProperty = $reflection->getProperty('connected');
        $connectedProperty->setAccessible(true);
        $connectedProperty->setValue($connection, true);
    }
}
