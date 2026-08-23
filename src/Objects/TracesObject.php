<?php

declare(strict_types=1);

namespace SLoggerLaravel\Objects;

use Generator;
use JsonException;

class TracesObject
{
    /** @var TraceCreateObject[] */
    private array $creating = [];

    /** @var TraceUpdateObject[] */
    private array $updating = [];

    public function addCreating(TraceCreateObject $trace): static
    {
        $this->creating[] = $trace;

        return $this;
    }

    public function addUpdating(TraceUpdateObject $trace): static
    {
        $this->updating[] = $trace;

        return $this;
    }

    /**
     * Reading, and only reading: these used to array_shift() their way through the
     * batch, so a second pass saw nothing and count() lied once anything had
     * serialised it. Whatever masks or sends a batch reads it more than once.
     *
     * @return Generator<int, TraceCreateObject>
     */
    public function iterateCreating(): Generator
    {
        yield from $this->creating;
    }

    /**
     * @return Generator<int, TraceUpdateObject>
     */
    public function iterateUpdating(): Generator
    {
        yield from $this->updating;
    }

    public function count(): int
    {
        return count($this->creating) + count($this->updating);
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        $creating = [];

        foreach ($this->iterateCreating() as $trace) {
            $creating[] = $trace->toJson();
        }

        $updating = [];

        foreach ($this->iterateUpdating() as $trace) {
            $updating[] = $trace->toJson();
        }

        return json_encode(
            [
                'c' => $creating,
                'u' => $updating,
            ],
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): TracesObject
    {
        $jsonData = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $traces = new TracesObject();

        foreach ($jsonData['c'] as $trace) {
            $traces->addCreating(
                TraceCreateObject::fromJson($trace)
            );
        }

        foreach ($jsonData['u'] as $trace) {
            $traces->addUpdating(
                TraceUpdateObject::fromJson($trace)
            );
        }

        return $traces;
    }
}
