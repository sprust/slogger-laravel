<?php

namespace SLoggerLaravel\LocalProxy;

use SLoggerLaravel\LocalProxy\Objects\LocalProxyStorageStateObject;

/**
 * One read of the sender state with the file held open. While the descriptor
 * lives, the inode cannot be freed and reused, so a later read resolving to
 * the same identity is the same content - not an inode recycled by two atomic
 * saves in between (an empty -> completed -> empty sequence).
 */
class LocalProxySenderStateRead
{
    /**
     * @param array{dev: int, ino: int}|null $identity null for a missing file
     * @param resource|null                  $handle
     */
    public function __construct(
        public readonly ?array $identity,
        public readonly LocalProxyStorageStateObject $state,
        private mixed $handle = null,
    ) {
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);

            $this->handle = null;
        }
    }
}
