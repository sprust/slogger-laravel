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
    public function __construct(
        protected string $apiToken,
        protected Connection $connection,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function sendTraces(TracesObject $traces): void
    {
        $this->connectIfNeed();

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
            ...(count($creatingTraces) ? ['c' => json_encode($creatingTraces)] : []),
            ...(count($updatingTraces) ? ['u' => json_encode($updatingTraces)] : []),
        ];

        if (count($payload) === 0) {
            return;
        }

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            $response = $this->exchange($payloadJson);
        } catch (ConnectionClosedException) {
            // exactly one retry, and only for a peer-closed connection:
            // retrying timeouts would turn a saturated receiver into a reconnect storm
            $this->connection->connect(
                apiToken: $this->apiToken
            );

            $response = $this->exchange($payloadJson);
        }

        if ($response !== 'received') {
            $this->connection->disconnect();

            throw new RuntimeException(
                'Unexpected response from socket server: ' . $response
            );
        }
    }

    protected function exchange(string $payloadJson): string
    {
        try {
            $this->connection->write($payloadJson);

            return $this->connection->read();
        } catch (Throwable $exception) {
            // a half-written frame or an unread response leaves the stream desynchronized:
            // drop the connection so the next attempt starts from a clean one
            $this->connection->disconnect();

            throw $exception;
        }
    }

    /**
     * @throws JsonException
     */
    protected function connectIfNeed(): void
    {
        if (!$this->connection->isConnected()) {
            $this->connection->connect(
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
