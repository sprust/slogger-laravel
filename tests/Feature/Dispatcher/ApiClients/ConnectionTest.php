<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\ApiClients;

use LogicException;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\Connection;
use SLoggerLaravel\Dispatcher\ApiClients\Socket\ConnectionClosedException;
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

    public function testWriteThrowsByTimeoutWhenSendBufferIsFullOnNonBlockingSocket(): void
    {
        $socketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        self::assertNotFalse($socketPair);

        [$left, $right] = $socketPair;

        stream_set_blocking($left, false);

        $connection = new Connection('local', new NullLogger(), timeoutSeconds: 1);
        $this->setConnectedSocket($connection, $left);

        // the peer never reads: the kernel send buffer fills up and
        // fwrite starts returning 0 — write() must throw by timeout instead of spinning forever
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to write to socket by timeout');

        try {
            $connection->write(str_repeat('x', 8 * 1024 * 1024));
        } finally {
            $connection->disconnect();
            fclose($right);
        }
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

    public function testReadThrowsConnectionClosedWhenPeerClosedConnection(): void
    {
        $socketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        self::assertNotFalse($socketPair);

        [$left, $right] = $socketPair;

        stream_set_blocking($left, false);

        // a big timeout: the closed connection must be reported immediately,
        // not as "Failed to read from socket by timeout" after the whole wait
        $connection = new Connection('local', new NullLogger(), timeoutSeconds: 30);
        $this->setConnectedSocket($connection, $left);

        fclose($right);

        $startedAt = microtime(true);

        try {
            $connection->read();

            self::fail('read() must throw when the peer closed the connection');
        } catch (ConnectionClosedException $exception) {
            self::assertStringContainsString('closed by peer', $exception->getMessage());
        }

        self::assertLessThan(5, microtime(true) - $startedAt);
        // the load-bearing part: connectIfNeed() must not reuse the dead connection
        self::assertFalse($connection->isConnected());
    }

    public function testReadThrowsConnectionClosedWhenPeerClosedConnectionAfterLengthPrefix(): void
    {
        $socketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        self::assertNotFalse($socketPair);

        [$left, $right] = $socketPair;

        stream_set_blocking($left, false);

        $connection = new Connection('local', new NullLogger(), timeoutSeconds: 30);
        $this->setConnectedSocket($connection, $left);

        // the prefix promises 8 bytes, the peer disappears before sending them
        fwrite($right, pack('N', 8));
        fclose($right);

        $this->expectException(ConnectionClosedException::class);

        $connection->read();
    }

    public function testWriteThrowsConnectionClosedWhenPeerClosedConnection(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errorString);

        self::assertNotFalse($server, "failed to start a test server: $errorString");

        $address = stream_socket_get_name($server, false);

        self::assertNotFalse($address);

        $client = stream_socket_client("tcp://$address", $errno, $errorString, 2.0);

        self::assertNotFalse($client, "failed to connect to the test server: $errorString");

        $accepted = stream_socket_accept($server, 2.0);

        self::assertNotFalse($accepted);

        stream_set_blocking($client, false);

        // the receiver goes away: restart, deploy, network fault
        fclose($accepted);
        fclose($server);

        $this->waitForEof($client);

        $connection = new Connection('local', new NullLogger(), timeoutSeconds: 30);
        $this->setConnectedSocket($connection, $client);

        try {
            // fwrite() into a closed socket reports success once (the chunk lands
            // in the send buffer) — write() must not treat that as delivered
            $connection->write('payload');

            self::fail('write() must throw when the peer closed the connection');
        } catch (ConnectionClosedException $exception) {
            self::assertStringContainsString('closed by peer', $exception->getMessage());
        }

        self::assertFalse($connection->isConnected());
    }

    public function testConnectionClosedExceptionIsRuntimeException(): void
    {
        // existing catch (RuntimeException) call sites must keep working
        self::assertInstanceOf(RuntimeException::class, new ConnectionClosedException('closed'));
    }

    public function testReadUsesTimeoutFromConstructor(): void
    {
        $socketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        self::assertNotFalse($socketPair);

        [$left, $right] = $socketPair;

        stream_set_blocking($left, false);

        $connection = new Connection('local', new NullLogger(), timeoutSeconds: 1);
        $this->setConnectedSocket($connection, $left);

        $startedAt = microtime(true);

        try {
            $connection->read();

            self::fail('read() must throw by timeout');
        } catch (RuntimeException $exception) {
            self::assertSame('Failed to read from socket by timeout', $exception->getMessage());
        } finally {
            $connection->disconnect();
            fclose($right);
        }

        self::assertLessThan(5, microtime(true) - $startedAt);
    }

    /**
     * Another connection to the same receiver, so a second sender never has to wait
     * for this one's exchange to finish.
     */
    public function testFreshGivesAnUnopenedConnectionOfTheSameKind(): void
    {
        $connection = new Connection('local', new NullLogger(), timeoutSeconds: 7);

        $fresh = $connection->fresh();

        self::assertNotSame($connection, $fresh);
        self::assertFalse($fresh->isConnected());
    }

    /**
     * And of the same kind means the subclass, not this: the pool would otherwise hand
     * out plain connections the moment two senders overlapped.
     */
    public function testFreshKeepsTheSubclass(): void
    {
        $connection = new SubclassedConnection('local', new NullLogger());

        self::assertInstanceOf(SubclassedConnection::class, $connection->fresh());
    }

    /**
     * @param resource $socket
     */
    private function waitForEof(mixed $socket): void
    {
        $deadline = microtime(true) + 2;

        while (!feof($socket) && microtime(true) < $deadline) {
            usleep(5000);
        }

        self::assertTrue(feof($socket), 'the peer close was not delivered to the client socket');
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

class SubclassedConnection extends Connection
{
}
