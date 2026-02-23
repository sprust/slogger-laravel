<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use SLoggerLaravel\Helpers\ArrayHelper;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class ArrayHelperTest extends BaseTestCase
{
    public function testFindKeyInsensitiveReturnsActualKey(): void
    {
        $data = [
            'Content-Type' => 'application/json',
            'X-Request-Id' => 'abc',
        ];

        $key = ArrayHelper::findKeyInsensitive($data, 'content-type');

        self::assertSame('Content-Type', $key);
    }

    public function testFindKeyInsensitiveReturnsNullWhenMissing(): void
    {
        $data = [
            'Accept' => 'application/json',
        ];

        $key = ArrayHelper::findKeyInsensitive($data, 'authorization');

        self::assertNull($key);
    }
}
