<?php

namespace SLoggerLaravel\Objects;

use Illuminate\Support\Carbon;
use JsonException;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;

class TraceUpdateObject
{
    /**
     * @param string[]|null             $tags
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public string $traceId,
        public string $status,
        public ?ProfilingObjects $profiling,
        public ?array $tags,
        public ?array $data,
        public ?float $duration,
        public ?float $memory,
        public ?float $cpu,
        public Carbon $parentLoggedAt
    ) {
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode(
            [
                'tid'   => $this->traceId,
                'st'    => $this->status,
                'pr'    => null, // TODO
                'tg'    => $this->tags,
                'dt'    => json_encode($this->data),
                'du'    => $this->duration,
                'mem'   => $this->memory,
                'cpu'   => $this->cpu,
                'lat'   => $this->parentLoggedAt,
            ],
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): TraceUpdateObject
    {
        $jsonData = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        try {
            $data = json_decode($jsonData['dt'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $data = [
                '__enc_err' => $exception->getMessage(),
            ];
        }

        return new TraceUpdateObject(
            traceId: $jsonData['tid'],
            status: $jsonData['st'],
            profiling: null, // TODO
            tags: $jsonData['tg'],
            data: $data,
            duration: isset($jsonData['du']) ? ((float) $jsonData['du']) : null,
            memory: isset($jsonData['mem']) ? ((float) $jsonData['mem']) : null,
            cpu: isset($jsonData['cpu']) ? ((float) $jsonData['cpu']) : null,
            parentLoggedAt: Carbon::parse($jsonData['lat']),
        );
    }
}
