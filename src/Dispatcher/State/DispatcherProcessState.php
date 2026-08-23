<?php

namespace SLoggerLaravel\Dispatcher\State;

use Closure;
use RuntimeException;
use SLoggerLaravel\LocalStorage;

readonly class DispatcherProcessState
{
    /** Part of the state file's name, so two packages cannot collide. */
    private const STATIC_UID = '678ed0bcb2d2c';

    public function __construct(private string $masterCommandName)
    {
    }

    /**
     * Deciding whether to take over and writing the new state have to be one step, or
     * two `start`s both read no state and only the second one is named on disk.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public function withLock(Closure $callback): mixed
    {
        $handle = @fopen($this->makeLockFilePath(), 'c');

        if ($handle === false) {
            // refusing to start would be worse than the race
            return $callback();
        }

        try {
            flock($handle, LOCK_EX);

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
            // every key the DTO needs, of the type it needs: a hand-edited file was
            // a TypeError taking down both commands. No state at all instead
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
     * Temporary file plus rename: atomic within a filesystem, so a reader sees either
     * the previous state or this one, never half of it.
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

        // named after this process, so nobody else writes to it and no lock is needed
        $temporaryPath = $pidFilePath . '.' . getmypid() . '.tmp';

        if (file_put_contents($temporaryPath, $json) === false) {
            throw new RuntimeException('Failed to write PID to file.');
        }

        if (!rename($temporaryPath, $pidFilePath)) {
            @unlink($temporaryPath);

            throw new RuntimeException('Failed to write PID to file.');
        }
    }

    /**
     * During takeover the new master saves its state while the old one is still
     * shutting down: an unconditional purge would delete the new master's.
     */
    public function purgeIfOwnedBy(int $masterPid): void
    {
        $this->withLock(function () use ($masterPid): void {
            $saved = $this->getSaved();

            if ($saved === null || $saved->masterPid !== $masterPid) {
                return;
            }

            $this->purge();
        });
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
        return app(LocalStorage::class)->makePath('dispatcher-state-' . self::STATIC_UID . '.json');
    }

    private function makeLockFilePath(): string
    {
        return $this->makeFilePath() . '.lock';
    }
}
