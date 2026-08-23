<?php

declare(strict_types=1);

use SLoggerLaravel\Helpers\TraceDataComplementer;

/**
 * A plain function, deliberately: a backtrace frame records `file` only when it has
 * no class, and that is what `excluded_file_masks` matches against.
 *
 * @return array<array{class?: string, file?: string, line: int}>
 */
function slogger_probe_trace(TraceDataComplementer $complementer): array
{
    $data = [];

    $complementer->inject($data);

    /** @var array<array{class?: string, file?: string, line: int}> $trace */
    $trace = $data['__trace'] ?? [];

    return $trace;
}
