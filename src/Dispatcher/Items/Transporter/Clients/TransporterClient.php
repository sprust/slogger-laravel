<?php

namespace SLoggerLaravel\Dispatcher\Items\Transporter\Clients;

use Closure;
use Illuminate\Contracts\Queue\Queue;

class TransporterClient implements TransporterClientInterface
{
    private ?Queue $connection = null;

    /**
     * @param Closure(): Queue $connectionResolver
     */
    public function __construct(
        private readonly string $apiToken,
        private readonly Closure $connectionResolver,
        private readonly string $queueName,
    ) {
    }

    public function dispatch(array $actions): void
    {
        if (!$this->connection) {
            $this->connection = ($this->connectionResolver)();
        }

        $this->connection->pushRaw(
            payload: json_encode([
                'id'      => uniqid(),
                'payload' => json_encode([
                    'tok' => $this->apiToken,
                    'acs' => $actions,
                ]),
            ]),
            queue: $this->queueName
        );
    }
}
