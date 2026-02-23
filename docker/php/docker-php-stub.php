<?php

declare(strict_types=1);

pcntl_async_signals(true);

$stop = false;

pcntl_signal(SIGTERM, function () use (&$stop) {
    $stop = true;

    echo time() . ": received SIGTERM. Exit...\n";
});

$echoAt = time();

while (!$stop) {
    if (time() - $echoAt > 10) {
        echo time() . ': ping' . PHP_EOL;

        $echoAt = time();
    }

    sleep(1);
}

echo time() . ': exit' . PHP_EOL;
