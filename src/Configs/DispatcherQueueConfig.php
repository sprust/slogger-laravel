<?php

namespace SLoggerLaravel\Configs;

use RuntimeException;

class DispatcherQueueConfig
{
    public const DEFAULT_SOCKET_CLIENT_TIMEOUT_SECONDS = 10;
    public const SOCKET_CLIENT_DIRECT                  = 'direct';
    public const SOCKET_CLIENT_PROXY                   = 'proxy';
    public const LOCAL_PROXY_SOCKET_FILENAME           = 'local-proxy.sock';

    public function getConnection(): string
    {
        $connection = config('slogger.dispatchers.queue.connection');

        if (!is_string($connection) || $connection === '') {
            throw new RuntimeException(
                'SLogger: queue dispatcher connection is not configured.'
                . ' Set the SLOGGER_DISPATCHER_QUEUE_CONNECTION env explicitly:'
                . ' telemetry must not silently share the application queue connection.'
            );
        }

        return $connection;
    }

    public function getName(): string
    {
        return (string) config('slogger.dispatchers.queue.name');
    }

    public function getDefaultApiClient(): string
    {
        return (string) config('slogger.dispatchers.queue.api_clients.default');
    }

    public function getSocketClientUrl(): string
    {
        return (string) config('slogger.dispatchers.queue.api_clients.socket.url');
    }

    public function getSocketClientType(): string
    {
        $type = config('slogger.dispatchers.queue.api_clients.socket.type', self::SOCKET_CLIENT_DIRECT);

        if (!in_array($type, [self::SOCKET_CLIENT_DIRECT, self::SOCKET_CLIENT_PROXY], true)) {
            throw new RuntimeException('SLogger: socket client type must be direct or proxy.');
        }

        return $type;
    }

    public function getSocketClientAddress(): string
    {
        if ($this->getSocketClientType() === self::SOCKET_CLIENT_DIRECT) {
            return $this->getSocketClientUrl();
        }

        return 'unix://' . base_path(
            $this->getLocalProxyTracesPath() . '/' . self::LOCAL_PROXY_SOCKET_FILENAME
        );
    }

    public function getLocalProxyTracesPath(): string
    {
        $path = config('slogger.local_proxy.traces_path', 'storage/slogger/traces');
        $path = is_string($path) ? trim($path) : '';
        $path = trim($path, '/');

        if ($path === '' || $path === '.') {
            throw new RuntimeException('SLogger: local proxy traces path must not be empty.');
        }

        $segments = preg_split('~/+~', $path);

        if ($segments === false || in_array('..', $segments, true)) {
            throw new RuntimeException("SLogger: local proxy traces path must not contain '..'.");
        }

        $segments = array_values(
            array_filter($segments, static fn(string $segment): bool => $segment !== '' && $segment !== '.')
        );

        return implode('/', $segments);
    }

    public function getSocketClientTimeoutSeconds(): int
    {
        // applications with a config published before this key existed get the default
        // instead of 0 — a zero timeout would fail every read on the first empty chunk
        $timeoutSeconds = (int) config(
            'slogger.dispatchers.queue.api_clients.socket.timeout_seconds'
        );

        return $timeoutSeconds > 0
            ? $timeoutSeconds
            : self::DEFAULT_SOCKET_CLIENT_TIMEOUT_SECONDS;
    }
}
