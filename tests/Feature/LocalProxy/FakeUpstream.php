<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\LocalProxy;

class FakeUpstream
{
    public static function run(string $address, string $token, string $capturePath): void
    {
        $errno  = 0;
        $error  = '';
        $server = stream_socket_server($address, $errno, $error);

        if ($server === false) {
            fwrite(STDERR, "failed to listen on [$address]: $error\n");
            exit(1);
        }

        $boundAddress = stream_socket_get_name($server, false);

        if ($boundAddress === false) {
            exit(1);
        }

        echo "$boundAddress\n";
        flush();

        for (; ;) {
            $connection = stream_socket_accept($server);

            if ($connection === false) {
                continue;
            }

            $auth = self::readFrame($connection);

            if ($auth === null) {
                fclose($connection);
                continue;
            }

            $payload = json_decode($auth, true);

            if (($payload['t'] ?? null) !== $token) {
                self::writeFrame($connection, 'denied');
                fclose($connection);
                continue;
            }

            self::writeFrame($connection, 'ok');

            while (($traces = self::readFrame($connection)) !== null) {
                file_put_contents($capturePath, $traces . "\n", FILE_APPEND | LOCK_EX);
                self::writeFrame($connection, 'received');
            }

            fclose($connection);
        }
    }

    /** @param resource $connection */
    private static function readFrame(mixed $connection): ?string
    {
        $header = self::readBytes($connection, 4);

        if ($header === null) {
            return null;
        }

        /** @var int<0, max> $length */
        $length = (int) (unpack('N', $header)[1] ?? 0);

        if ($length === 0) {
            return '';
        }

        return self::readBytes($connection, $length);
    }

    /**
     * @param resource    $connection
     * @param int<1, max> $length
     */
    private static function readBytes(mixed $connection, int $length): ?string
    {
        $data = '';

        while (strlen($data) < $length) {
            /** @var int<1, max> $remaining */
            $remaining = $length - strlen($data);
            $chunk     = fread($connection, $remaining);

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

    /** @param resource $connection */
    private static function writeFrame(mixed $connection, string $payload): void
    {
        fwrite($connection, pack('N', strlen($payload)) . $payload);
    }
}
