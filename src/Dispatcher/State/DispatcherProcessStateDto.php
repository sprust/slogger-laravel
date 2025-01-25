<?php

namespace SLoggerLaravel\Dispatcher\State;

class DispatcherProcessStateDto
{
    /**
     * @param int[] $childProcessPids
     */
    public function __construct(
        public string $dispatcher,
        public string $masterCommandName,
        public int $masterPid,
        public string $childCommandName,
        public array $childProcessPids,
    ) {
    }
}
