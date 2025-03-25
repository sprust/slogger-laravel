<?php

namespace SLoggerLaravel\Dispatcher\State;

use RuntimeException;
use SLoggerLaravel\LocalStorage;

readonly class DispatcherProcessState
{
    private string $staticUid;

    public function __construct(private string $masterCommandName)
    {
        $this->staticUid = "678ed0bcb2d2c";
    }

    public function getMasterCommandName(): string
    {
        return $this->masterCommandName;
    }

    public function getSaved(): ?DispatcherProcessStateDto
    {
        $filePath = $this->makeFilePath();

        if (!file_exists($filePath)) {
            return null;
        }

        $contents = file_get_contents($filePath);

        if (!$contents) {
            return null;
        }

        $data = json_decode($contents, true);

        return new DispatcherProcessStateDto(
            dispatcher: $data['dispatcher'],
            masterCommandName: $data['masterCommandName'],
            masterPid: $data['masterPid'],
            childCommandName: $data['childCommandName'],
            childProcessPids: $data['childProcessPids'],
        );
    }

    public function save(DispatcherProcessStateDto $state): void
    {
        $pidFilePath = $this->makeFilePath();

        $data = [
            'dispatcher'        => $state->dispatcher,
            'masterCommandName' => $state->masterCommandName,
            'masterPid'         => $state->masterPid,
            'childCommandName'  => $state->childCommandName,
            'childProcessPids'  => $state->childProcessPids,
        ];

        if (file_put_contents($pidFilePath, json_encode($data, JSON_PRETTY_PRINT)) === false) {
            throw new RuntimeException('Failed to write PID to file.');
        }
    }

    public function purge(): void
    {
        $pidFilePath = $this->makeFilePath();

        if (!file_exists($pidFilePath)) {
            return;
        }

        if (!unlink($pidFilePath)) {
            throw new RuntimeException('Failed to remove PID file.');
        }
    }

    private function makeFilePath(): string
    {
        return app(LocalStorage::class)->makePath("dispatcher-state-$this->staticUid.json");
    }
}
