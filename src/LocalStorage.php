<?php

namespace SLoggerLaravel;

readonly class LocalStorage
{
    private string $storagePath;

    public function __construct()
    {
        $this->storagePath = rtrim(storage_path(), '/') . '/slogger';

        if (!file_exists($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    public function makePath(string $fileName): string
    {
        return $this->storagePath . '/' . $fileName;
    }
}
