<?php

namespace SLoggerLaravel\Dispatcher\ApiClients\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use SLoggerLaravel\Dispatcher\ApiClients\ApiClientInterface;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;

class HttpClient implements ApiClientInterface
{
    public function __construct(protected ClientInterface $client)
    {
    }

    /**
     * @throws GuzzleException
     */
    public function sendTraces(TracesObject $traces): void
    {
        $this->createTraces($traces);
        $this->updateTraces($traces);
    }

    /**
     * @throws GuzzleException
     */
    protected function createTraces(TracesObject $traces): void
    {
        $payload = [];

        foreach ($traces->iterateCreating() as $trace) {
            $payload[] = [
                'trace_id'        => $trace->traceId,
                'parent_trace_id' => $trace->parentTraceId,
                'type'            => $trace->type,
                'status'          => $trace->status,
                'tags'            => $trace->tags,
                'data'            => json_encode($trace->data),
                'duration'        => $trace->duration,
                'memory'          => $trace->memory,
                'cpu'             => $trace->cpu,
                'is_parent'       => $trace->isParent,
                'logged_at'       => (float) ($trace->loggedAt->unix()
                    . '.' . $trace->loggedAt->microsecond),
            ];
        }

        $this->client->request('post', '/traces-api', [
            'json' => [
                'traces' => $payload,
            ],
        ]);
    }

    /**
     * @throws GuzzleException
     */
    protected function updateTraces(TracesObject $traces): void
    {
        $payload = [];

        foreach ($traces->iterateUpdating() as $trace) {
            $payload[] = [
                'trace_id' => $trace->traceId,
                'status'   => $trace->status,
                ...(is_null($trace->profiling)
                    ? []
                    : ['profiling' => $this->prepareProfiling($trace->profiling)]),
                ...(is_null($trace->tags)
                    ? []
                    : ['tags' => $trace->tags]),
                ...(is_null($trace->data)
                    ? []
                    : ['data' => json_encode($trace->data)]),
                ...(is_null($trace->duration)
                    ? []
                    : ['duration' => $trace->duration]),
                ...(is_null($trace->memory)
                    ? []
                    : ['memory' => $trace->memory]),
                ...(is_null($trace->cpu)
                    ? []
                    : ['cpu' => $trace->cpu]),
            ];
        }

        $this->client->request('patch', '/traces-api', [
            'json' => [
                'traces' => $payload,
            ],
        ]);
    }

    /**
     * @return array{
     *     main_caller: string,
     *     items: array{
     *      raw: string,
     *      calling: string,
     *      callable: string,
     *      data: array{
     *          name: string,
     *          value: int|float
     *      }[]
     *     }[]
     * }
     */
    private function prepareProfiling(ProfilingObjects $profiling): array
    {
        $result = [];

        foreach ($profiling->getItems() as $item) {
            $result[] = [
                'raw'      => $item->raw,
                'calling'  => $item->calling,
                'callable' => $item->callable,
                'data'     => [
                    $this->makeProfileDataItem('wait (us)', $item->data->waitTimeInUs),
                    $this->makeProfileDataItem('calls', $item->data->numberOfCalls),
                    $this->makeProfileDataItem('cpu', $item->data->cpuTime),
                    $this->makeProfileDataItem('mem (b)', $item->data->memoryUsageInBytes),
                    $this->makeProfileDataItem('mem peak (b)', $item->data->peakMemoryUsageInBytes),
                ],
            ];
        }

        return [
            'main_caller' => $profiling->getMainCaller(),
            'items'       => $result,
        ];
    }

    /**
     * @return array{name: string, value: int|float}
     */
    private function makeProfileDataItem(string $name, int|float $value): array
    {
        return [
            'name'  => $name,
            'value' => $value,
        ];
    }
}
