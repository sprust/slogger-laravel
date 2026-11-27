<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\LocalProxy\Metrics\Support;

use SLoggerLaravel\Events\LocalProxyBacklogExceededEvent;

class RecordingLocalProxyAlertListener
{
    /** @var list<LocalProxyBacklogExceededEvent> */
    public static array $handled = [];

    public static function reset(): void
    {
        self::$handled = [];
    }

    public function handle(LocalProxyBacklogExceededEvent $event): void
    {
        self::$handled[] = $event;
    }
}
