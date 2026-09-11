<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Currency;
use PHPUnit\Framework\TestCase;

/**
 * Money written as text, which the API does only in mail (ADR 0035).
 *
 * The first unit test in the repository: a pure function, with no application
 * to boot. The expectations are written with escapes rather than the symbols,
 * which root CLAUDE.md section 15 keeps out of source.
 */
final class CurrencyTest extends TestCase
{
    /**
     * The same sums the web application's `formatMoney` writes, in the same
     * locale, so a mail and the page it links to agree.
     */
    public function test_an_amount_reads_as_the_web_application_writes_it(): void
    {
        $this->assertSame("\u{20AC}129.00", Currency::EUR->format(12900));
        $this->assertSame("\u{00A3}35.00", Currency::GBP->format(3500));
        $this->assertSame("DKK\u{00A0}950.00", Currency::DKK->format(95000));
    }

    public function test_thousands_are_grouped(): void
    {
        $this->assertSame("\u{20AC}14,500.00", Currency::EUR->format(1450000));
    }
}
