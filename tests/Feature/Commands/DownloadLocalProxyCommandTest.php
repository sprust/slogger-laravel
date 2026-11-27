<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use SLoggerLaravel\Commands\DownloadLocalProxyCommand;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DownloadLocalProxyCommandTest extends BaseTestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function testDownloadsVerifiesAndInstallsBinaryWhenPackageDisabled(): void
    {
        config()->set('slogger.enabled', false);
        $binary = "go-binary\0contents";
        $this->fakeDownloads($binary);
        $destination = $this->destination();

        $exitCode = Artisan::call('slogger:local-proxy:download', [
            '--release' => '2.3.0',
            '--path'    => $destination,
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame($binary, file_get_contents($destination));
        self::assertNotSame(0, fileperms($destination) & 0111);
    }

    public function testDoesNotReplaceExistingBinaryWithoutForce(): void
    {
        $this->fakeDownloads('new-binary');
        $destination = $this->destination();
        file_put_contents($destination, 'old-binary');

        $exitCode = Artisan::call('slogger:local-proxy:download', [
            '--release' => '2.3.0',
            '--path'    => $destination,
        ]);

        self::assertSame(1, $exitCode);
        self::assertSame('old-binary', file_get_contents($destination));
    }

    public function testForceReplacesExistingBinary(): void
    {
        $this->fakeDownloads('new-binary');
        $destination = $this->destination();
        file_put_contents($destination, 'old-binary');

        $exitCode = Artisan::call('slogger:local-proxy:download', [
            '--release' => '2.3.0',
            '--path'    => $destination,
            '--force'   => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame('new-binary', file_get_contents($destination));
    }

    public function testChecksumFailureKeepsPreviousBinary(): void
    {
        $destination = $this->destination();
        file_put_contents($destination, 'old-binary');
        $this->fakeDownloads('new-binary', str_repeat('0', 64));

        $exitCode = Artisan::call('slogger:local-proxy:download', [
            '--release' => '2.3.0',
            '--path'    => $destination,
            '--force'   => true,
        ]);

        self::assertSame(1, $exitCode);
        self::assertSame('old-binary', file_get_contents($destination));
    }

    public function testHttpFailureKeepsPreviousBinary(): void
    {
        $destination = $this->destination();
        file_put_contents($destination, 'old-binary');
        Http::fake(['https://github.com/*' => Http::response('missing', 404)]);

        $exitCode = Artisan::call('slogger:local-proxy:download', [
            '--release' => '2.3.0',
            '--path'    => $destination,
            '--force'   => true,
        ]);

        self::assertSame(1, $exitCode);
        self::assertSame('old-binary', file_get_contents($destination));
    }

    public function testUnsupportedPlatformFailsBeforeDownloading(): void
    {
        Http::fake();
        $command = new UnsupportedPlatformDownloadCommand();
        $command->setLaravel($this->getApp());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Only Linux amd64', $tester->getDisplay());
        Http::assertNothingSent();
    }

    private function fakeDownloads(string $binary, ?string $checksum = null): void
    {
        $base = 'https://github.com/sprust/slogger-laravel/releases/download/v2.3.0';
        $checksum ??= hash('sha256', $binary);
        Http::fake([
            "$base/slogger-local-proxy_linux_amd64"   => Http::response($binary),
            "$base/slogger-local-proxy_checksums.txt" => Http::response(
                "$checksum  slogger-local-proxy_linux_amd64\n"
            ),
        ]);
    }

    private function destination(): string
    {
        $directory = sys_get_temp_dir() . '/slogger-local-proxy-' . bin2hex(random_bytes(6));
        mkdir($directory, 0755, true);
        $this->paths[] = $directory;

        $destination   = $directory . '/slogger-local-proxy';
        $this->paths[] = $destination;

        return $destination;
    }
}

class UnsupportedPlatformDownloadCommand extends DownloadLocalProxyCommand
{
    protected function operatingSystem(): string
    {
        return 'Darwin';
    }
}
