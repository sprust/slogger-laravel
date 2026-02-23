<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\State;

use Illuminate\Contracts\Container\BindingResolutionException;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessState;
use SLoggerLaravel\Dispatcher\State\DispatcherProcessStateDto;
use SLoggerLaravel\LocalStorage;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class DispatcherProcessStateTest extends BaseTestCase
{
    /**
     * @throws BindingResolutionException
     */
    public function testSaveGetAndPurge(): void
    {
        $state = new DispatcherProcessState('slogger:dispatcher:start');

        $dto = new DispatcherProcessStateDto(
            dispatcher: 'queue',
            masterCommandName: 'slogger:dispatcher:start',
            masterPid: 111,
            childCommandName: 'artisan queue:work',
            childProcessPids: [222, 333],
        );

        $state->save($dto);

        $loaded = $state->getSaved();

        self::assertNotNull($loaded);
        self::assertSame('queue', $loaded->dispatcher);
        self::assertSame('slogger:dispatcher:start', $loaded->masterCommandName);
        self::assertSame(111, $loaded->masterPid);
        self::assertSame('artisan queue:work', $loaded->childCommandName);
        self::assertSame([222, 333], $loaded->childProcessPids);

        $state->purge();

        self::assertNull($state->getSaved());

        $this->cleanupStateFile();
    }

    /**
     * @throws BindingResolutionException
     */
    private function cleanupStateFile(): void
    {
        $file = $this->getApp()->make(LocalStorage::class)
            ->makePath('dispatcher-state-678ed0bcb2d2c.json');

        if (file_exists($file)) {
            unlink($file);
        }
    }
}
