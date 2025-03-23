<?php

namespace SLoggerLaravel\Profiling\Dto;

class ProfilingObjects
{
    /**
     * @var ProfilingObject[]
     */
    private array $items = [];

    public function __construct(private readonly string $mainCaller)
    {
    }

    public function getMainCaller(): string
    {
        return $this->mainCaller;
    }

    public function add(ProfilingObject $object): static
    {
        $this->items[] = $object;

        return $this;
    }

    /**
     * @return ProfilingObject[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function toJson(): string
    {
        return json_encode([
            'mainCaller' => $this->mainCaller,
            'items'      => array_map(
                fn(ProfilingObject $item) => [
                    'raw'      => $item->raw,
                    'calling'  => $item->calling,
                    'callable' => $item->callable,
                    'data'     => [
                        'numberOfCalls'          => $item->data->numberOfCalls,
                        'waitTimeInUs'           => $item->data->waitTimeInUs,
                        'cpuTime'                => $item->data->cpuTime,
                        'memoryUsageInBytes'     => $item->data->memoryUsageInBytes,
                        'peakMemoryUsageInBytes' => $item->data->peakMemoryUsageInBytes,
                    ],
                ],
                $this->items
            ),
        ]);
    }

    public static function fromJson(string $json): ProfilingObjects
    {
        $jsonData = json_decode($json, true);

        $result = new ProfilingObjects($jsonData['mainCaller']);

        foreach ($jsonData['items'] as $item) {
            $data = $item['data'];

            $result->add(
                new ProfilingObject(
                    raw: $item['raw'],
                    calling: $item['calling'],
                    callable: $item['callable'],
                    data: new ProfilingDataObject(
                        numberOfCalls: $data['numberOfCalls'],
                        waitTimeInUs: $data['waitTimeInUs'],
                        cpuTime: $data['cpuTime'],
                        memoryUsageInBytes: $data['memoryUsageInBytes'],
                        peakMemoryUsageInBytes: $data['peakMemoryUsageInBytes']
                    )
                )
            );
        }

        return $result;
    }
}
