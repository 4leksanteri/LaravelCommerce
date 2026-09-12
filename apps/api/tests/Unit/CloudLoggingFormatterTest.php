<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\CloudLoggingFormatter;
use Monolog\DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * The one field a log collector reads (ADR 0044).
 *
 * Worth its own test because getting it wrong fails silently. A stream with no
 * `severity` still arrives, still carries every line, and is recorded as errors
 * throughout - so the thing a log exists for, telling a real failure from a
 * debug line, is the thing it cannot do, and nothing anywhere says so.
 */
final class CloudLoggingFormatterTest extends TestCase
{
    public function test_it_carries_the_severity_the_record_had(): void
    {
        $formatted = $this->format(Level::Warning, 'A shop could not be paid yet.');

        $this->assertSame('WARNING', $formatted['severity']);
        $this->assertSame('A shop could not be paid yet.', $formatted['message']);
    }

    /**
     * Monolog's levels and Cloud Logging's severities are the same eight words,
     * which is why this copies rather than maps. If that ever stops being true,
     * this is where it is found out.
     */
    public function test_every_level_is_a_severity_of_the_same_name(): void
    {
        foreach (Level::cases() as $level) {
            $this->assertSame($level->getName(), $this->format($level, 'anything')['severity']);
        }
    }

    /**
     * One record is one line. A formatter that emitted two would split every
     * stack trace into separate entries at the far end, which is the problem
     * JSON was chosen to avoid.
     */
    public function test_a_record_with_newlines_in_it_is_still_one_line(): void
    {
        $line = (new CloudLoggingFormatter)->format(
            $this->record(Level::Error, "Something failed\n#0 /app/thing.php(12)\n#1 /app/other.php(3)"),
        );

        $this->assertStringNotContainsString("\n", trim($line));
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Level $level, string $message): array
    {
        $line = (new CloudLoggingFormatter)->format($this->record($level, $message));

        $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function record(Level $level, string $message): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(true),
            channel: 'testing',
            level: $level,
            message: $message,
        );
    }
}
