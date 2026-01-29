<?php

declare(strict_types=1);

namespace SLoggerLaravel\Dispatcher\Items\Queue\ApiClients\Socket;

use Illuminate\Support\Carbon;
use RuntimeException;
use SLoggerLaravel\Dispatcher\Items\Queue\ApiClients\ApiClientInterface;
use SLoggerLaravel\Objects\TracesObject;
use Throwable;

class SocketClient implements ApiClientInterface
{
    public function __construct(
        protected string $apiToken,
        protected Connection $connection,
    ) {
    }

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

        $payloadJson = json_encode($payload);

        try {
            $this->connection->write($payloadJson);
        } catch (Throwable) {
            $this->connection->connect(
                apiToken: $this->apiToken
            );

            $this->connection->write($payloadJson);
        }

        $response = $this->connection->read();

        if ($response !== 'received') {
            throw new RuntimeException(
                'Unexpected response from socket server: ' . $response
            );
        }
    }

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
        return $loggedAt->toDateTimeString('milliseconds');
    }
}
