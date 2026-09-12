<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * One log line, as JSON, with the one field Google Cloud Logging reads.
 *
 * The container writes to its own stream and the platform collects it
 * (ADR 0044). What that platform does with a line depends entirely on its
 * shape:
 *
 * ```text
 * plain text on stderr    every line is ERROR, including the debug ones
 * JSON with `severity`    the level this application actually meant
 * ```
 *
 * Cloud Run marks anything on stderr as an error unless the payload says
 * otherwise, so a suite of INFO lines arrives looking like an incident. The
 * `severity` key is what stops that, and it is the whole reason this class
 * exists rather than `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`:
 * Monolog writes `level_name`, which nothing at Google reads.
 *
 * **The names need no translating.** Monolog's levels and Cloud Logging's
 * severities are the same eight words - DEBUG through EMERGENCY - so this
 * copies rather than maps. A table here would be a table to get wrong.
 *
 * The other reason for JSON is stack traces. A trace written as plain text is
 * one log entry per line at the far end, which is forty entries that have to
 * be read back together; as JSON the whole exception stays inside the entry it
 * belongs to.
 */
final class CloudLoggingFormatter extends JsonFormatter
{
    /**
     * @return array<string, mixed>
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        $normalized = parent::normalizeRecord($record);

        $normalized['severity'] = $record->level->getName();

        return $normalized;
    }
}
