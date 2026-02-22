<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Model;

use App\Models\TestModel;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\ModelWatcher;

class ModelWatcherTest extends BaseChildWatcherTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDatabase();
    }

    protected function getTraceType(): string
    {
        return 'model';
    }

    protected function getWatcherClass(): string
    {
        return ModelWatcher::class;
    }

    protected function successCallback(): Closure
    {
        $model = TestModel::query()->create([
            'name'      => 'Initial',
            'api_token' => 'initial-token',
            'password'  => 'initial-password',
        ]);

        $modelId = $model->getKey();

        return static function () use ($modelId): void {
            /** @var TestModel $model */
            $model = TestModel::query()->findOrFail($modelId);

            $model->update([
                'name'      => 'Updated',
                'api_token' => 'updated-token',
                'password'  => 'updated-password',
            ]);
        };
    }

    protected function assertSuccess(TraceCreateObject $creatingTrace, TraceUpdateObject $updatingTrace): void
    {
        // no action
    }

    private function configureDatabase(): void
    {
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
