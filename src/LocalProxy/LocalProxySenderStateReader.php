<?php

namespace SLoggerLaravel\LocalProxy;

use SLoggerLaravel\Configs\LocalProxyConfig;
use SLoggerLaravel\LocalProxy\Objects\LocalProxyStorageStateObject;
use Throwable;

class LocalProxySenderStateReader
{
    private const SUPPORTED_VERSION = 1;

    /**
     * The state with the file held open, so a caller comparing two reads can
     * trust their identities: the descriptor pins the inode, and the proxy's
     * atomic-save-by-rename can never reuse it for another content.
     *
     * Mirrors storage.StateStore.Load on the Go side: a missing state file is
     * an empty state (nothing was ever sent); anything else unreadable or
     * invalid - including an existing path that is not a regular file, which
     * Go reads as an error like EISDIR - makes the state unknown (null).
     */
    public function read(string $directory): ?LocalProxySenderStateRead
    {
        $path = $directory . '/' . LocalProxyConfig::STATE_FILENAME;

        clearstatcache(true, $path);

        $stat = @lstat($path);

        if ($stat === false) {
            // lstat fails on ENOENT and on EACCES alike; the directory listing
            // arbitrates: a listed name that cannot be stat'ed is unknown,
            // not missing
            $names = @scandir($directory);

            if ($names === false || in_array(LocalProxyConfig::STATE_FILENAME, $names, true)) {
                return null;
            }

            return new LocalProxySenderStateRead(
                identity: null,
                state: new LocalProxyStorageStateObject(file: null, offset: 0, completed: false),
            );
        }

        // never open anything but a plain file: reading a FIFO would block
        if (($stat['mode'] & 0170000) !== 0100000) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        // the identity of the file actually read, not of the path at lstat time
        $readStat = fstat($handle);
        $contents = stream_get_contents($handle);

        if ($readStat === false || $contents === false) {
            fclose($handle);

            return null;
        }

        $state = $this->decode($contents);

        if ($state === null) {
            fclose($handle);

            return null;
        }

        return new LocalProxySenderStateRead(
            identity: ['dev' => $readStat['dev'], 'ino' => $readStat['ino']],
            state: $state,
            handle: $handle,
        );
    }

    private function decode(string $contents): ?LocalProxyStorageStateObject
    {
        try {
            $data = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $version   = $data['version'] ?? null;
        $file      = $data['file'] ?? '';
        $offset    = $data['offset'] ?? null;
        $completed = $data['completed'] ?? false;

        if ($version !== self::SUPPORTED_VERSION
            || !is_string($file)
            || ($file !== '' && !preg_match(LocalProxyConfig::HOURLY_FILE_NAME_PATTERN, $file))
            || !is_int($offset)
            || $offset < 0
            || !is_bool($completed)
            || ($completed && $file === '')
        ) {
            return null;
        }

        return new LocalProxyStorageStateObject(
            file: $file === '' ? null : $file,
            offset: $offset,
            completed: $completed,
        );
    }
}
