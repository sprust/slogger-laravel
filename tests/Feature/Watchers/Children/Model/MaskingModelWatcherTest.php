<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Model;

use App\Models\TestModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Children\ModelWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class MaskingModelWatcherTest extends BaseWatcherTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDatabase();
    }

    public function testMasksChanges(): void
    {
        $this->registerWatcher(
            watcherClass: ModelWatcher::class,
            config: [
                'masks' => [
                    '*' => [
                        '*token*',
                        '*password*',
                    ],
                ],
            ],
        );

        $this->registerWatcher(JobWatcher::class, null);

        $model = TestModel::query()->create([
            'name'      => 'Initial',
            'api_token' => 'initial-token',
            'password'  => 'initial-password',
        ]);

        $modelId = $model->getKey();

        dispatch(static function () use ($modelId): void {
            /** @var TestModel $model */
            $model = TestModel::query()->findOrFail($modelId);

            $model->update([
                'name'      => 'Updated',
                'api_token' => 'updated-token',
                'password'  => 'updated-password',
            ]);
        });

        $trace = $this->getModelCreatingTrace();

        $changes = $trace->data['changes'] ?? [];

        self::assertArrayHasKey('api_token', $changes);
        self::assertArrayHasKey('password', $changes);
        self::assertNotSame('updated-token', $changes['api_token']);
        self::assertNotSame('updated-password', $changes['password']);
        self::assertSame('Updated', $changes['name'] ?? null);
    }

    private function getModelCreatingTrace(): TraceCreateObject
    {
        $creating = $this->dispatcher->findCreating(
            type: 'model',
            status: TraceStatusEnum::Success,
            tag: 'updated',
            isParent: false,
        );

        self::assertCount(1, $creating);

        return $creating[0];
    }

    private function configureDatabase(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('test_models');
        Schema::create('test_models', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('api_token')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });
    }
}
