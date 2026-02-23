<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use App\Models\TestModel;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SLoggerLaravel\Helpers\DataFormatter;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use Throwable;

class DataFormatterTest extends BaseTestCase
{
    public function testExceptionFormatting(): void
    {
        try {
            $this->throwExampleException();
        } catch (Throwable $exception) {
            /** @var array<string, mixed> $data */
            $data = DataFormatter::exception($exception);

            self::assertSame('Example error', $data['message']);
            self::assertSame(get_class($exception), $data['exception']);
            self::assertSame(__FILE__, $data['file']);
            self::assertIsInt($data['line']);
            self::assertIsArray($data['trace']);
            self::assertNotEmpty($data['trace']);
        }
    }

    public function testModelFormatting(): void
    {
        $model = new TestModel();

        self::assertSame(TestModel::class . ':<new>', DataFormatter::model($model));

        Schema::dropIfExists('test_models');

        Schema::create('test_models', function ($table) {
            $table->id();
            $table->timestamps();
        });

        /** @var TestModel $saved */
        $saved = TestModel::query()->create();

        self::assertSame(
            TestModel::class . ':' . $saved->getKey(),
            DataFormatter::model($saved)
        );
    }

    private function throwExampleException(): void
    {
        throw new RuntimeException('Example error');
    }
}
