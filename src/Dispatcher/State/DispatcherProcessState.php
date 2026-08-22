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

        $contents = @file_get_contents($filePath);

        if (!$contents) {
            return null;
        }

        $data = json_decode($contents, true);

        if (!is_array($data)
            || !is_string($data['dispatcher'] ?? null)
            || !is_string($data['masterCommandName'] ?? null)
            || !is_int($data['masterPid'] ?? null)
            || !is_string($data['childCommandName'] ?? null)
            || !is_array($data['childProcessPids'] ?? null)
            || array_filter($data['childProcessPids'], static fn(mixed $pid): bool => !is_int($pid))
        ) {
            // every key the DTO needs, and of the type it needs: a file written by an
            // older version, or hand-edited, is valid JSON with a key missing or a pid
            // written as a string - and either was a TypeError that took down `start`,
            // `stop` and the recovery path alike, until someone deleted the file by
            // hand. Treat it as no state at all: the worst case is a stale file, and
            // the next save overwrites it
            return null;
        }

        return new DispatcherProcessStateDto(
            dispatcher: $data['dispatcher'],
            masterCommandName: $data['masterCommandName'],
            masterPid: $data['masterPid'],
            childCommandName: $data['childCommandName'],
            childProcessPids: $data['childProcessPids'],
        );
    }

    /**
     * Writes to a temporary file and renames it into place. `rename()` is atomic
     * within a filesystem, so a concurrent reader sees either the previous state or
     * this one, never the half of it that has been flushed so far.
     */
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

        $json = json_encode($data, JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new RuntimeException('Failed to encode dispatcher state.');
        }

        $temporaryPath = $pidFilePath . '.' . getmypid() . '.tmp';

        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write PID to file.');
        }

        if (!rename($temporaryPath, $pidFilePath)) {
            @unlink($temporaryPath);

            throw new RuntimeException('Failed to write PID to file.');
        }
    }

    /**
     * Purges the state file only when it still belongs to the given master.
     *
     * During takeover the new master overwrites the state file while the old
     * master is still shutting down: an unconditional purge from the old master
     * would delete the new master's state and make it unmanageable.
     */
    public function purgeIfOwnedBy(int $masterPid): void
    {
        $saved = $this->getSaved();

        if ($saved === null || $saved->masterPid !== $masterPid) {
            return;
        }

        $this->purge();
    }

    public function purge(): void
    {
        $pidFilePath = $this->makeFilePath();

        if (!file_exists($pidFilePath)) {
            return;
        }

        if (!@unlink($pidFilePath) && file_exists($pidFilePath)) {
            throw new RuntimeException('Failed to remove PID file.');
        }
    }

    private function makeFilePath(): string
    {
        return app(LocalStorage::class)->makePath("dispatcher-state-$this->staticUid.json");
    }
}
