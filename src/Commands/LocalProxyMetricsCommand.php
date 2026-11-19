<?php

declare(strict_types=1);

namespace SLoggerLaravel\Commands;

use Illuminate\Console\Command;
use SLoggerLaravel\Configs\LocalProxyConfig;
use SLoggerLaravel\LocalProxy\LocalProxyAlertChecker;
use SLoggerLaravel\LocalProxy\LocalProxyMetricsReader;
use SLoggerLaravel\LocalProxy\Objects\LocalProxyMetricsObject;
use Throwable;

class LocalProxyMetricsCommand extends Command
{
    private const FORMAT_LINE = 'line';
    private const FORMAT_JSON = 'json';

    private const MIN_INTERVAL_SECONDS = 0.1;
    // an hour between polls is far past any sane watch cadence, and keeps the
    // microseconds conversion far from integer overflow
    private const MAX_INTERVAL_SECONDS = 3600;

    protected $signature = 'slogger:local-proxy:metrics
        {format=line : Output format: line or json}
        {--interval=1 : Seconds between polls (0.1 to 3600)}
        {--once : Print a single snapshot and exit}';

    protected $description = 'Print SLogger local proxy metrics';

    public function __construct(
        private readonly LocalProxyMetricsReader $metricsReader,
        private readonly LocalProxyAlertChecker $alertChecker,
        private readonly LocalProxyConfig $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string $format */
        $format = $this->argument('format');

        if (!in_array($format, [self::FORMAT_LINE, self::FORMAT_JSON], true)) {
            $this->error("Unknown format [$format]. Available: line, json.");

            return self::FAILURE;
        }

        /** @var string $intervalOption */
        $intervalOption = $this->option('interval');

        // is_numeric alone passes "1e309", which casts to INF, and "1e20",
        // which overflows when converted to microseconds for usleep
        $intervalSeconds = is_numeric($intervalOption) ? (float) $intervalOption : NAN;

        if (!is_finite($intervalSeconds)
            || $intervalSeconds < self::MIN_INTERVAL_SECONDS
            || $intervalSeconds > self::MAX_INTERVAL_SECONDS
        ) {
            $this->error(
                'The --interval option must be a number of seconds between '
                . self::MIN_INTERVAL_SECONDS . ' and ' . self::MAX_INTERVAL_SECONDS . '.'
            );

            return self::FAILURE;
        }

        $once = (bool) $this->option('once');

        do {
            try {
                $available = $this->printSnapshotOrError($format, clearScreen: !$once);
            } catch (Throwable $exception) {
                // e.g. an invalid local_proxy.traces_path fails the path
                // resolution: report it like any other unavailable metrics
                // instead of crashing out of the loop
                $this->printError($format, 'metrics unavailable: ' . $exception->getMessage());

                $available = false;
            }

            if ($once) {
                // useful for monitoring checks: no readable snapshot is a failure
                return $available ? self::SUCCESS : self::FAILURE;
            }

            usleep((int) ($intervalSeconds * 1_000_000));
        } while (true);
    }

    private function printSnapshot(LocalProxyMetricsObject $metrics, string $format): void
    {
        if ($format === self::FORMAT_JSON) {
            $this->line(
                json_encode(
                    $metrics->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );

            return;
        }

        $pairs = [];

        foreach ($metrics->toFlatArray() as $key => $value) {
            $pairs[] = $key . '=' . $this->formatScalar($value);
        }

        $this->line(implode(';', $pairs));
    }

    private function printSnapshotOrError(string $format, bool $clearScreen): bool
    {
        $metrics = $this->metricsReader->read();

        if ($clearScreen && $format === self::FORMAT_JSON) {
            $this->clearScreen();
        }

        if ($metrics === null) {
            $this->printError(
                $format,
                'metrics unavailable: no readable snapshot at ' . $this->metricsReader->getMetricsFilePath()
            );

            return false;
        }

        $maxAgeSeconds = $this->config->getMetricsMaxSnapshotAgeSeconds();

        if ($metrics->isStale($maxAgeSeconds)) {
            $this->printError(
                $format,
                'metrics snapshot is stale'
                . ' (collected at ' . $metrics->collectedAt->toIso8601String()
                . ", max age {$maxAgeSeconds}s)"
            );

            return false;
        }

        $this->alertChecker->check($metrics);
        $this->printSnapshot($metrics, $format);

        return true;
    }

    private function printError(string $format, string $message): void
    {
        if ($format === self::FORMAT_JSON) {
            $this->line(
                json_encode(
                    ['error' => $message],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );

            return;
        }

        $this->line('error="' . str_replace('"', "'", $message) . '"');
    }

    private function formatScalar(string|int|float|bool|null $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default         => (string) $value,
        };
    }

    private function clearScreen(): void
    {
        $this->output->write("\033[2J\033[;H");
    }
}
