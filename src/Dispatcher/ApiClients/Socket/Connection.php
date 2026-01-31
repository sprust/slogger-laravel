<?php

declare(strict_types=1);

namespace SLoggerLaravel\Dispatcher\ApiClients\Socket;

use JsonException;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class Connection
{
    /**
     * @var resource|null
     */
    protected mixed $socket = null;

    protected int $socketBufferSize = 8024;

    protected bool $connected = false;

    protected int $lengthPrefixLength = 4;

    protected int $timeoutSeconds = 5;

    public function __construct(
        protected string $socketAddress,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function connect(string $apiToken): void
    {
        $payload = json_encode(
            [
                't' => $apiToken,
            ],
            JSON_THROW_ON_ERROR
        );

        $this->disconnect();

        $errorString = '';

        try {
            $socket = stream_socket_client(
                address: $this->socketAddress,
                error_code: $errno,
                error_message: $errorString,
                timeout: 2.0,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf(
                    '%s: %s%s',
                    $this->socketAddress,
                    ($errorString ? "socket error: $errorString, message: " : ''),
                    $exception->getMessage()
                )
            );
        }

        if (!$socket) {
            throw new RuntimeException(
                sprintf(
                    '%s: %s',
                    $this->socketAddress,
                    ($errorString ? "socket error: $errorString" : 'unknown error')
                )
            );
        }

        socket_set_blocking($socket, false);

        $this->socket    = $socket;
        $this->connected = true;

        $this->write(
            payload: $payload
        );

        $response = $this->read();

        if ($response !== 'ok') {
            throw new RuntimeException(
                "auth failed to [$this->socketAddress]: $response"
            );
        }

        $this->logger->debug(
            "connected to [$this->socketAddress]"
        );
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
        }

        $this->socket    = null;
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function write(string $payload): void
    {
        $this->checkConnection();

        $payloadLength = strlen($payload);
        $buffer        = pack('N', $payloadLength) . $payload;
        $bufferLength  = $payloadLength + $this->lengthPrefixLength;

        $sentBytes  = 0;
        $bufferSize = $this->socketBufferSize;

        $timeout = null;

        /** @var resource $socket */
        $socket = $this->socket;

        while ($sentBytes < $bufferLength) {
            $chunk = substr($buffer, $sentBytes, $bufferSize);

            try {
                $bytes = fwrite(
                    stream: $socket,
                    data: $chunk,
                );
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    message: $exception->getMessage(),
                    previous: $exception,
                );
            }

            if ($bytes === false) {
                if ($timeout === null) {
                    $timeout = time();

                    continue;
                }

                if ((time() - $timeout) < $this->timeoutSeconds) {
                    continue;
                }

                throw new RuntimeException(
                    'Failed to write to socket by timeout'
                );
            }

            $timeout = null;

            $sentBytes += $bytes;
        }
    }

    public function read(): string
    {
        $this->checkConnection();

        /** @var resource $socket */
        $socket = $this->socket;

        $lengthHeader = '';

        $timeout = null;

        while (strlen($lengthHeader) < 4) {
            try {
                $chunk = fread(
                    stream: $socket,
                    length: 4 - strlen($lengthHeader)
                );
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    message: $exception->getMessage(),
                );
            }

            if (!$chunk) {
                if ($timeout === null) {
                    $timeout = time();

                    continue;
                }

                if ((time() - $timeout) < $this->timeoutSeconds) {
                    continue;
                }

                throw new RuntimeException(
                    'Failed to read from socket by timeout'
                );
            }

            $timeout = null;

            $lengthHeader .= $chunk;
        }

        $response   = ""; // TODO: what!?
        $dataLength = (int) (unpack('N', $lengthHeader)[1] ?? 0);
        $bufferSize = $this->socketBufferSize;

        if ($dataLength === 0) {
            throw new RuntimeException('Empty response');
        }

        $timeout = null;

        while (strlen($response) < $dataLength) {
            $chunk = fread(
                stream: $socket,
                /** @phpstan-ignore-next-line argument.type */
                length: min($bufferSize, $dataLength - strlen($response))
            );

            if (!$chunk) {
                if ($timeout === null) {
                    $timeout = time();

                    continue;
                }

                if ((time() - $timeout) < $this->timeoutSeconds) {
                    continue;
                }

                throw new RuntimeException(
                    'Failed to read from socket by timeout'
                );
            }

            $timeout = null;

            $response .= $chunk;
        }

        return $response;
    }

    protected function checkConnection(): void
    {
        if (!$this->connected) {
            throw new LogicException(
                'Socket is not connected. Please call connect() first.'
            );
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
