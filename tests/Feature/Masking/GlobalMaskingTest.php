<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Masking;

use Illuminate\Log\LogManager;
use Illuminate\Support\Carbon;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\ApiClients\ApiClientInterface;
use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\SendTracesJob;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Children\LogWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

/**
 * Masking is the dispatcher job's business: the traced application collects the data
 * as it is, and the batch is masked on its way out.
 */
class GlobalMaskingTest extends BaseWatcherTestCase
{
    public function testTheTracedApplicationDoesNotMask(): void
    {
        $this->registerWatcher(JobWatcher::class, null);
        $this->registerWatcher(LogWatcher::class, null);

        dispatch(static function (): void {
            /**
             * for support for Laravel 10, 12
             *
             * @var LogManager|null $logger
             */
            $logger = logger();

            $logger?->info('order created', ['customer_email' => 'customer@example.test']);
        });

        $creating = $this->dispatcher->findCreating(type: 'log');

        self::assertCount(1, $creating);

        // nothing is spent on masking while the application is serving the request
        self::assertSame(
            'customer@example.test',
            $creating[0]->data['context']['customer_email'] ?? null
        );
    }

    public function testTracesAreMaskedOnTheirWayOut(): void
    {
        $sent = $this->sendThroughJob(
            [
                'context' => [
                    'customer_email' => 'customer@example.test',
                    'API_KEY'        => 'key-1',
                    'order_id'       => 42,
                ],
                'connection_name' => 'redis',
            ]
        );

        $context = $sent['context'];

        // an address identifies rather than authenticates: enough is left to tell two
        // customers apart, not enough to reach either of them
        self::assertSame('cu*****************st', $context['customer_email']);

        // a key authenticates: nothing of it survives
        self::assertSame(MaskHelper::FULL_MASK, $context['API_KEY']);

        // nothing in this key matches either list
        self::assertSame(42, $context['order_id']);

        // `connection_name` matches `_name` but describes the trace, not the traced data
        self::assertSame('redis', $sent['connection_name']);
    }

    public function testEmptyKeyListsTurnMaskingOff(): void
    {
        // all three of them: value patterns match without any key at all, so leaving
        // them in place would keep masking addresses
        $this->getApp()['config']->set('slogger.masking.full_keys', []);
        $this->getApp()['config']->set('slogger.masking.partial_keys', []);
        $this->getApp()['config']->set('slogger.masking.value_patterns', []);

        // the masker reads the config once, when it is built
        $this->getApp()->forgetInstance(TraceDataMasker::class);

        $sent = $this->sendThroughJob(
            ['context' => ['customer_email' => 'customer@example.test']]
        );

        self::assertSame('customer@example.test', $sent['context']['customer_email']);
    }

    public function testClearingOneListLeavesTheOthersWorking(): void
    {
        $this->getApp()['config']->set('slogger.masking.partial_keys', []);
        $this->getApp()['config']->set('slogger.masking.value_patterns', []);

        $this->getApp()->forgetInstance(TraceDataMasker::class);

        $sent = $this->sendThroughJob(
            [
                'context' => [
                    'customer_email' => 'customer@example.test',
                    'API_KEY'        => 'key-1',
                ],
            ]
        );

        self::assertSame('customer@example.test', $sent['context']['customer_email']);
        self::assertSame(MaskHelper::FULL_MASK, $sent['context']['API_KEY']);
    }

    public function testAnAddressIsMaskedWhereNoKeyPointsAtIt(): void
    {
        $sent = $this->sendThroughJob(
            [
                'context' => [
                    // the key says nothing about what the value holds, and the address
                    // is only part of the string
                    'notifiable' => 'Anonymous:mail,customer@example.test',
                    'note'       => 'invoice sent',
                ],
            ]
        );

        self::assertSame(
            'Anonymous:mail,cu*****************st',
            $sent['context']['notifiable']
        );

        // nothing in it matches: left readable
        self::assertSame('invoice sent', $sent['context']['note']);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function sendThroughJob(array $data): array
    {
        $job = new SendTracesJob(
            (new TracesObject())->addCreating($this->makeTrace($data))
        );

        $apiClient = new class implements ApiClientInterface {
            /**
             * @var array<string, mixed>
             */
            public array $sentData = [];

            public function sendTraces(TracesObject $traces): void
            {
                foreach ($traces->iterateCreating() as $trace) {
                    $this->sentData = $trace->data;
                }
            }
        };

        $job->handle(
            $this->getApp()->make(Processor::class),
            $apiClient,
            new GeneralConfig(),
            $this->getApp()->make(TraceDataMasker::class)
        );

        return $apiClient->sentData;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function makeTrace(array $data): TraceCreateObject
    {
        return new TraceCreateObject(
            traceId: 'trace-1',
            parentTraceId: null,
            type: 'log',
            status: TraceStatusEnum::Success->value,
            tags: [],
            data: $data,
            duration: null,
            memory: null,
            cpu: null,
            isParent: false,
            loggedAt: Carbon::now()
        );
    }
}
