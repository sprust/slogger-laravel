<?php

declare(strict_types=1);

namespace App\Events;

readonly class SerializableEvent
{
    public function __construct(
        public string $name,
        public int $attempt,
    ) {
    }
}
