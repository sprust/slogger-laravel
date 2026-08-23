<?php

namespace SLoggerLaravel;

use RuntimeException;

readonly class LocalStorage
{
    private string $storagePath;

    public function __construct()
    {
        $this->storagePath = rtrim(storage_path(), '/') . '/slogger';

        if (is_dir($this->storagePath)) {
            return;
        }

        // @ plus the retest: the loser of a mkdir race would raise a warning, which
        // Laravel turns into an exception out of container resolution
        if (!@mkdir($this->storagePath, 0755, true) && !is_dir($this->storagePath)) {
            throw new RuntimeException("Failed to create the slogger storage directory: $this->storagePath");
        }
    }

    public function makePath(string $fileName): string
    {
        return $this->storagePath . '/' . $fileName;
    }
}
