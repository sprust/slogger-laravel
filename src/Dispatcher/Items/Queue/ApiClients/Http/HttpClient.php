<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue\ApiClients\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use SLoggerLaravel\Dispatcher\Items\Queue\ApiClients\ApiClientInterface;
use SLoggerLaravel\Objects\TraceObjects;
use SLoggerLaravel\Objects\TraceUpdateObjects;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;

class HttpClient implements ApiClientInterface
{
    public function __construct(protected ClientInterface $client)
    {
    }

    /**
     * @throws GuzzleException
     */
    public function sendTraces(TraceObjects $traceObjects): void
    {
        $traces = [];

        foreach ($traceObjects->get() as $traceObject) {
            $traces[] = [
                'trace_id'        => $traceObject->traceId,
                'parent_trace_id' => $traceObject->parentTraceId,
                'type'            => $traceObject->type,
                'status'          => $traceObject->status,
                'tags'            => $traceObject->tags,
                'data'            => json_encode($traceObject->data),
                'duration'        => $traceObject->duration,
                'memory'          => $traceObject->memory,
                'cpu'             => $traceObject->cpu,
                'logged_at'       => (float) ($traceObject->loggedAt->unix()
                    . '.' . $traceObject->loggedAt->microsecond),
            ];
        }

        $this->client->request('post', '/traces-api', [
            'json' => [
                'traces' => $traces,
            ],
        ]);
    }

    /**
     * @throws GuzzleException
     */
    public function updateTraces(TraceUpdateObjects $traceObjects): void
    {
        $traces = [];

        foreach ($traceObjects->get() as $traceObject) {
            $traces[] = [
                'trace_id' => $traceObject->traceId,
                'status'   => $traceObject->status,
                ...(is_null($traceObject->profiling)
                    ? []
                    : ['profiling' => $this->prepareProfiling($traceObject->profiling)]),
                ...(is_null($traceObject->tags)
                    ? []
                    : ['tags' => $traceObject->tags]),
                ...(is_null($traceObject->data)
                    ? []
                    : ['data' => json_encode($traceObject->data)]),
                ...(is_null($traceObject->duration)
                    ? []
                    : ['duration' => $traceObject->duration]),
                ...(is_null($traceObject->memory)
                    ? []
                    : ['memory' => $traceObject->memory]),
                ...(is_null($traceObject->cpu)
                    ? []
                    : ['cpu' => $traceObject->cpu]),
            ];
        }

        $this->client->request('patch', '/traces-api', [
            'json' => [
                'traces' => $traces,
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
