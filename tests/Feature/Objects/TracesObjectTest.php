<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Objects;

use Illuminate\Support\Carbon;
use JsonException;
use RuntimeException;
use SLoggerLaravel\DataResolver;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use stdClass;

class TracesObjectTest extends BaseTestCase
{
    public function testCountIncludesCreatingAndUpdatingItems(): void
    {
        $traces = new TracesObject();

        self::assertSame(0, $traces->count());

        $traces->addCreating($this->makeCreateTrace(['create' => true]));
        $traces->addUpdating($this->makeUpdateTrace(['update' => true]));

        self::assertSame(2, $traces->count());
    }

    public function testIteratorsReturnItemsAndClearInternalState(): void
    {
        $createTrace = $this->makeCreateTrace(['key' => 'create']);
        $updateTrace = $this->makeUpdateTrace(['key' => 'update']);

        $traces = (new TracesObject())
            ->addCreating($createTrace)
            ->addUpdating($updateTrace);

        self::assertSame([$createTrace], iterator_to_array($traces->iterateCreating()));
        self::assertSame([$updateTrace], iterator_to_array($traces->iterateUpdating()));
        self::assertSame(0, $traces->count());
    }

    /**
     * @throws JsonException
     */
    public function testNormalizeForQueueHandlesAllDataTypes(): void
    {
        $stdObject       = new stdClass();
        $stdObject->name = 'payload';

        $carbon = Carbon::create(2024, 1, 1, 0, 0, 0, 'UTC')
            ?: throw new RuntimeException('Failed to create Carbon instance');

        $closure = static fn(): string => 'closure-value';

        $dataResolver = new DataResolver(
            static fn(): array => [
                'resolved_bool'    => true,
                'resolved_closure' => static fn(): string => 'nested-closure',
            ]
        );

        $data = [
            'null'         => null,
            'true'         => true,
            'false'        => false,
            'int'          => 123,
            'float'        => 45.67,
            'string'       => 'value',
            'empty_string' => '',
            'array'        => [1, 'two', 3.2, null],
            'assoc_array'  => ['nested' => 'value'],
            'object'       => $stdObject,
            'carbon'       => $carbon,
            'closure'      => $closure,
            'resolver'     => $dataResolver,
            'nested'       => [
                'closure'  => static fn(): string => 'inner',
                'resolver' => new DataResolver(
                    static fn(): array => [
                        'deep'         => 'value',
                        'deep_closure' => static fn(): string => 'deep-closure',
                    ]
                ),
                'object' => $stdObject,
            ],
        ];

        $traces = (new TracesObject())
            ->addCreating($this->makeCreateTrace($data))
            ->addUpdating($this->makeUpdateTrace($data));

        $tracesJson = $traces->toJson();

        $unserializedTraces = TracesObject::fromJson($tracesJson);

        /** @var TraceCreateObject[] $creating */
        $creating = iterator_to_array($unserializedTraces->iterateCreating());
        /** @var TraceUpdateObject[] $updating */
        $updating = iterator_to_array($unserializedTraces->iterateUpdating());

        self::assertCount(1, $creating);
        self::assertCount(1, $updating);

        $this->assertNormalizedData($creating[0]->data, $carbon);
        $this->assertNormalizedData($updating[0]->data, $carbon);
    }

    /**
     * @throws JsonException
     */
    public function testToJsonAndFromJsonKeepLoggedAtInUtc(): void
    {
        $createLoggedAt = Carbon::create(2024, 1, 1, 3, 0, 0, 'Europe/Moscow')
            ?->setMicroseconds(123000)
            ?: throw new RuntimeException('Failed to create Carbon instance');

        $updateLoggedAt = Carbon::create(2024, 1, 1, 3, 0, 1, 'Europe/Moscow')
            ?->setMicroseconds(654000)
            ?: throw new RuntimeException('Failed to create Carbon instance');

        $traces = (new TracesObject())
            ->addCreating($this->makeCreateTrace(
                data: ['create' => true],
                loggedAt: $createLoggedAt
            ))
            ->addUpdating($this->makeUpdateTrace(
                data: ['update' => true],
                parentLoggedAt: $updateLoggedAt
            ));

        $restored = TracesObject::fromJson($traces->toJson());

        $creating = iterator_to_array($restored->iterateCreating());
        $updating = iterator_to_array($restored->iterateUpdating());

        self::assertCount(1, $creating);
        self::assertCount(1, $updating);
        self::assertSame('UTC', $creating[0]->loggedAt->getTimezone()->getName());
        self::assertSame('UTC', $updating[0]->parentLoggedAt->getTimezone()->getName());
        self::assertSame('2024-01-01 00:00:00.123000', $creating[0]->loggedAt->format('Y-m-d H:i:s.u'));
        self::assertSame('2024-01-01 00:00:01.654000', $updating[0]->parentLoggedAt->format('Y-m-d H:i:s.u'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function makeCreateTrace(array $data, ?Carbon $loggedAt = null): TraceCreateObject
    {
        return new TraceCreateObject(
            traceId: 'trace-create',
            parentTraceId: 'parent-trace',
            type: 'request',
            status: 'started',
            tags: ['tag'],
            data: $data,
            duration: 1.2,
            memory: 12.2,
            cpu: 1.2,
            isParent: false,
            loggedAt: $loggedAt ?? Carbon::create(2024, 1, 1, 0, 0, 0, 'UTC')
                ?: throw new RuntimeException('Failed to create Carbon instance')
        );
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function makeUpdateTrace(?array $data, ?Carbon $parentLoggedAt = null): TraceUpdateObject
    {
        return new TraceUpdateObject(
            traceId: 'trace-update',
            status: 'success',
            profiling: null,
            tags: ['tag'],
            data: $data,
            duration: 1.2,
            memory: 12.2,
            cpu: 1.2,
            parentLoggedAt: $parentLoggedAt ?? Carbon::create(2024, 1, 1, 0, 0, 0, 'UTC')
                ?: throw new RuntimeException('Failed to create Carbon instance')
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertNormalizedData(?array $data, Carbon $carbon): void
    {
        if ($data === null) {
            return;
        }

        self::assertNull($data['null']);
        self::assertTrue($data['true']);
        self::assertFalse($data['false']);
        self::assertSame(123, $data['int']);
        self::assertSame(45.67, $data['float']);
        self::assertSame('value', $data['string']);
        self::assertSame('', $data['empty_string']);
        self::assertSame([1, 'two', 3.2, null], $data['array']);
        self::assertSame(['nested' => 'value'], $data['assoc_array']);
        self::assertSame(['name' => 'payload'], $data['object']);
        self::assertSame($carbon->toDateTimeString(), Carbon::parse($data['carbon'])->toDateTimeString());
        self::assertSame([], $data['closure']);
        self::assertSame([], $data['resolver']);
        self::assertSame([], $data['nested']['closure']);
        self::assertSame([], $data['nested']['resolver']);
        self::assertSame(['name' => 'payload'], $data['nested']['object']);
    }
}
