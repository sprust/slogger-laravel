<?php

declare(strict_types=1);

namespace SLoggerLaravel\Dispatcher\ApiClients\Socket;

use Illuminate\Support\Carbon;
use JsonException;
use RuntimeException;
use SLoggerLaravel\Dispatcher\ApiClients\ApiClientInterface;
use SLoggerLaravel\Objects\TracesObject;
use Throwable;

class SocketClient implements ApiClientInterface
{
    /**
     * Sending a batch is one write followed by one read on a length-prefixed stream,
     * and the protocol has no way to tell whose reply is whose. Under a runtime that
     * can switch coroutines inside an I/O call, senders sharing a connection would tear
     * each other's frames and read each other's answers, so each holds one for the
     * whole exchange.
     *
     * Where batches are sent one at a time - php-fpm, `queue:work`, the dispatcher's
     * own workers - the connection this was built with is handed out every time, and
     * nothing else is ever opened.
     */
    private readonly ConnectionPool $connections;

    public function __construct(
        protected string $apiToken,
        protected Connection $connection,
    ) {
        $this->connections = new ConnectionPool(
            fn(): Connection => $this->connection->fresh()
        );

        // free from the start: anything beyond it is opened only when a second sender
        // wants one while this is taken
        $this->connections->release($connection);
    }

    /**
     * @throws Throwable
     * @throws JsonException
     */
    public function sendTraces(TracesObject $traces): void
    {
        // held from the first byte written to the last byte read, so that a sender
        // running beside this one never writes into the middle of this exchange
        $connection = $this->connections->acquire();

        try {
            $this->send($connection, $traces);
        } finally {
            $this->connections->release($connection);
        }
    }

    /**
     * @throws Throwable
     * @throws JsonException
     */
    protected function send(Connection $connection, TracesObject $traces): void
    {
        $this->connectIfNeed($connection);

        $iterator = $traces->iterateCreating();

        $creatingTraces = [];

        foreach ($iterator as $trace) {
            $creatingTraces[] = [
                'tid' => $trace->traceId,
                ...(is_null($trace->parentTraceId) ? [] : ['ptid' => $trace->parentTraceId]),
                'tp'  => $trace->type,
                'st'  => $trace->status,
                ...(!count($trace->tags) ? [] : ['tgs' => $trace->tags]),
                'dt'  => $trace->data,
                ...(is_null($trace->duration) ? [] : ['dur' => $trace->duration]),
                ...(is_null($trace->memory) ? [] : ['mem' => $trace->memory]),
                ...(is_null($trace->cpu) ? [] : ['cpu' => $trace->cpu]),
                'lat' => $this->prepareLoggedAt($trace->loggedAt),
            ];
        }

        $updatingTraces = [];

        $iterator = $traces->iterateUpdating();

        foreach ($iterator as $trace) {
            $updatingTraces[] = [
                'tid'  => $trace->traceId,
                'st'   => $trace->status,
                ...(is_null($trace->tags) ? [] : ['tgs' => $trace->tags]),
                ...(is_null($trace->data) ? [] : ['dt' => $trace->data]),
                ...(is_null($trace->duration) ? [] : ['dur' => $trace->duration]),
                ...(is_null($trace->memory) ? [] : ['mem' => $trace->memory]),
                ...(is_null($trace->cpu) ? [] : ['cpu' => $trace->cpu]),
                'plat' => $this->prepareLoggedAt($trace->parentLoggedAt),
            ];
        }

        $payload = [
            ...(count($creatingTraces) ? ['c' => json_encode($creatingTraces, JSON_THROW_ON_ERROR)] : []),
            ...(count($updatingTraces) ? ['u' => json_encode($updatingTraces, JSON_THROW_ON_ERROR)] : []),
        ];

        if (count($payload) === 0) {
            return;
        }

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            $response = $this->exchange($connection, $payloadJson);
        } catch (ConnectionClosedException) {
            // exactly one retry, and only for a peer-closed connection:
            // retrying timeouts would turn a saturated receiver into a reconnect storm
            $connection->connect(
                apiToken: $this->apiToken
            );

            $response = $this->exchange($connection, $payloadJson);
        }

        if ($response !== 'received') {
            $connection->disconnect();

            throw new RuntimeException(
                'Unexpected response from socket server: ' . $response
            );
        }
    }

    /**
     * @throws Throwable
     */
    protected function exchange(Connection $connection, string $payloadJson): string
    {
        try {
            $connection->write($payloadJson);

            return $connection->read();
        } catch (Throwable $exception) {
            // a half-written frame or an unread response leaves the stream desynchronized:
            // drop the connection so the next attempt starts from a clean one
            $connection->disconnect();

            throw $exception;
        }
    }

    /**
     * @throws JsonException
     */
    protected function connectIfNeed(Connection $connection): void
    {
        if (!$connection->isConnected()) {
            $connection->connect(
                apiToken: $this->apiToken
            );
        }
    }

    protected function prepareLoggedAt(Carbon $loggedAt): string
    {
        return $loggedAt
            ->clone()
            ->format('Y-m-d\TH:i:s.vP');
    }
}
