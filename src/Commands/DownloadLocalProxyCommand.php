<?php

declare(strict_types=1);

namespace SLoggerLaravel\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DownloadLocalProxyCommand extends Command
{
    private const ASSET     = 'slogger-local-proxy_linux_amd64';
    private const CHECKSUMS = 'slogger-local-proxy_checksums.txt';

    protected $signature = 'slogger:local-proxy:download
        {--release= : Package release version to download}
        {--path= : Destination binary path}
        {--force : Replace an existing binary}';

    protected $description = 'Download the SLogger local proxy binary into vendor/bin';

    public function handle(): int
    {
        /** @var string|null $temporaryPath */
        $temporaryPath = null;

        try {
            $this->checkPlatform();

            $version     = $this->resolveVersion();
            $destination = $this->option('path');

            if (!is_string($destination) || $destination === '') {
                $destination = base_path('vendor/bin/slogger-local-proxy');
            }

            if (is_file($destination) && !$this->option('force')) {
                throw new RuntimeException("Binary already exists at [$destination]. Use --force to replace it.");
            }

            $directory = dirname($destination);

            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Failed to create directory [$directory].");
            }

            $baseUrl = 'https://github.com/sprust/slogger-laravel/releases/download/v' . $version;

            $createdTemporaryPath = tempnam($directory, '.slogger-local-proxy-');

            if ($createdTemporaryPath === false) {
                throw new RuntimeException("Failed to create a temporary file in [$directory].");
            }

            $temporaryPath = $createdTemporaryPath;

            $this->download("$baseUrl/" . self::ASSET, $temporaryPath);
            $checksums = $this->download("$baseUrl/" . self::CHECKSUMS);

            $this->verifyChecksum($temporaryPath, $checksums);

            if (!chmod($temporaryPath, 0755)) {
                throw new RuntimeException('Failed to make the downloaded binary executable.');
            }

            if (!rename($temporaryPath, $destination)) {
                throw new RuntimeException("Failed to move the binary to [$destination].");
            }

            $temporaryPath = null;
            $this->info("SLogger local proxy v$version installed at [$destination].");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('SLogger local proxy: ' . $exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    protected function operatingSystem(): string
    {
        return PHP_OS_FAMILY;
    }

    protected function machineArchitecture(): string
    {
        return php_uname('m');
    }

    private function checkPlatform(): void
    {
        if ($this->operatingSystem() !== 'Linux'
            || !in_array($this->machineArchitecture(), ['x86_64', 'amd64'], true)
        ) {
            throw new RuntimeException('Only Linux amd64 is currently supported.');
        }
    }

    private function resolveVersion(): string
    {
        $version = $this->option('release');

        if (!is_string($version) || $version === '') {
            try {
                $version = InstalledVersions::getPrettyVersion('slogger/laravel');
            } catch (Throwable) {
                $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
                $version  = $composer['version'] ?? '';
            }
        }

        $version = ltrim((string) $version, 'v');

        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new RuntimeException('A valid release version is required, for example 2.3.0.');
        }

        return $version;
    }

    private function download(string $url, ?string $sink = null): string
    {
        $request = Http::timeout(60);

        if ($sink !== null) {
            $request = $request->withOptions(['sink' => $sink]);
        }

        $response = $request->get($url)->throw();

        return $sink === null ? $response->body() : '';
    }

    private function verifyChecksum(string $binaryPath, string $checksums): void
    {
        $expected = null;

        foreach (preg_split('/\R/', $checksums) ?: [] as $line) {
            if (preg_match('/^([a-fA-F0-9]{64})\s+\*?(.+)$/', trim($line), $matches)
                && $matches[2] === self::ASSET
            ) {
                $expected = strtolower($matches[1]);
                break;
            }
        }

        $actual = hash_file('sha256', $binaryPath);

        if ($expected === null || $actual === false || !hash_equals($expected, $actual)) {
            throw new RuntimeException('Binary checksum verification failed.');
        }
    }
}
