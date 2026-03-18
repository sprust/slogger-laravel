<?php

namespace SLoggerLaravel\Objects;

use Illuminate\Support\Carbon;
use JsonException;

class TraceCreateObject
{
    /**
     * @param string[]             $tags
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $traceId,
        public ?string $parentTraceId,
        public string $type,
        public string $status,
        public array $tags,
        public array $data,
        public ?float $duration,
        public ?float $memory,
        public ?float $cpu,
        public bool $isParent,
        public Carbon $loggedAt
    ) {
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode(
            [
                'tid'  => $this->traceId,
                'ptid' => $this->parentTraceId,
                'tp'   => $this->type,
                'st'   => $this->status,
                'tgs'  => $this->tags,
                'dt'   => json_encode($this->data),
                'dur'  => $this->duration,
                'mem'  => $this->memory,
                'cpu'  => $this->cpu,
                'isP'  => $this->isParent,
                'lat'  => $this->loggedAt,
            ],
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): TraceCreateObject
    {
        $jsonData = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        try {
            $data = json_decode($jsonData['dt'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $data = [
                '__enc_err' => $exception->getMessage(),
            ];
        }

        return new TraceCreateObject(
            traceId: $jsonData['tid'],
            parentTraceId: $jsonData['ptid'],
            type: $jsonData['tp'],
            status: $jsonData['st'],
            tags: $jsonData['tgs'],
            data: $data,
            duration: isset($jsonData['du']) ? ((float) $jsonData['du']) : null,
            memory: isset($jsonData['mem']) ? ((float) $jsonData['mem']) : null,
            cpu: isset($jsonData['cpu']) ? ((float) $jsonData['cpu']) : null,
            isParent: $jsonData['isP'],
            loggedAt: Carbon::parse($jsonData['lat']),
        );
    }
}
