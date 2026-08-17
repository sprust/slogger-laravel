<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\ApiClients;

/**
 * A minimal stand-in for the receiver, run in a separate process.
 *
 * Speaks the length-prefixed protocol of `Connection`: answers `ok` to the auth
 * frame and `received` to a traces batch, then closes the connection — which is
 * exactly what a receiver restart looks like from the client side.
 */
class FakeSocketReceiver
{
    /**
     * Prints the bound address as the first stdout line, then serves
     * `$connections` connections, one exchange each.
     */
    public static function run(string $address, int $connections): void
    {
        $errno       = 0;
        $errorString = '';

        $server = stream_socket_server($address, $errno, $errorString);

        if ($server === false) {
            fwrite(STDERR, "failed to listen on [$address]: $errorString\n");

            exit(1);
        }

        $boundAddress = stream_socket_get_name($server, false);

        if ($boundAddress === false) {
            fwrite(STDERR, "failed to resolve the bound address\n");

            exit(1);
        }

        echo "$boundAddress\n";
        flush();

        for ($i = 0; $i < $connections; $i++) {
            $connection = stream_socket_accept($server, 10);

            if ($connection === false) {
                exit(1);
            }

            // auth handshake
            self::readFrame($connection);
            self::writeFrame($connection, 'ok');

            // one traces batch
            self::readFrame($connection);
            self::writeFrame($connection, 'received');

            // let the response reach the client before dropping the connection
            usleep(50000);

            fclose($connection);
        }

        fclose($server);
    }

    /**
     * @param resource $connection
     */
    private static function readFrame(mixed $connection): ?string
    {
        $header = self::readBytes($connection, 4);

        if ($header === null) {
            return null;
        }

        /** @var int<0, max> $length */
        $length = (int) (unpack('N', $header)[1] ?? 0);

        return self::readBytes($connection, $length);
    }

    /**
     * @param resource    $connection
     * @param int<0, max> $length
     */
    private static function readBytes(mixed $connection, int $length): ?string
    {
        $data = '';

        while (strlen($data) < $length) {
            /** @var int<1, max> $rest */
            $rest = $length - strlen($data);

            $chunk = fread($connection, $rest);

            if ($chunk === false || $chunk === '') {
                if (feof($connection)) {
                    return null;
                }

                usleep(1000);

                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @param resource $connection
     */
    private static function writeFrame(mixed $connection, string $payload): void
    {
        fwrite($connection, pack('N', strlen($payload)) . $payload);
    }
}
