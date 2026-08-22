<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Model;

use App\Models\TestModel;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\ModelWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class ModelWatcherTest extends BaseChildWatcherTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDatabase();
    }

    public function testChangesAreMaskedOnTheirWayOut(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch($this->getSuccessCallback());

        $creating = $this->dispatcher->findCreating(type: 'model');

        self::assertCount(1, $creating);

        $masked = app(TraceDataMasker::class)->mask($creating[0]->data);

        self::assertSame(MaskHelper::FULL_MASK, $masked['changes']['api_token']);
        self::assertSame(MaskHelper::FULL_MASK, $masked['changes']['password']);

        // `name` is in partial_keys: it identifies a person rather than authenticates
        // one, so enough is kept to tell two records apart
        self::assertSame('Up***ed', $masked['changes']['name']);
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
        /** @var TestModel $model */
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

    protected function assertSuccess(TraceCreateObject $creatingTrace): void
    {
        $data = $creatingTrace->data;

        self::assertSame('updated', $data['action']);
        self::assertSame(TestModel::class, $data['model']);

        // the traced application does not mask; `changes` is one level in, where the
        // dispatcher job reaches it
        self::assertSame('updated-token', $data['changes']['api_token']);
        self::assertSame('updated-password', $data['changes']['password']);
        self::assertSame('Updated', $data['changes']['name']);
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
