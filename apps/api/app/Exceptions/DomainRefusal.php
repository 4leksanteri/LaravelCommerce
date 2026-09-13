<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * A refusal this marketplace expects.
 *
 * Every subclass becomes a **409** in `bootstrap/app.php`: the caller was
 * allowed, what they sent was valid, and what is in the way is the state of the
 * world. Somebody bought the last one. The order shipped while the page was
 * open. The shop was reviewed by the other member of staff a moment ago.
 *
 * **None of them is reported** (ADR 0045). `ShouldntReport` is the framework's
 * own marker and the handler checks it before writing anything, so "This is
 * sold out." stops arriving in the log as an ERROR with a stack attached. A log
 * is read by somebody looking for a real failure and is billed by the line, and
 * a sold-out listing is neither a failure nor worth paying to store.
 *
 * **The marker is here rather than on each subclass** so that the next domain
 * refusal is silent by construction. A rule that has to be remembered when a
 * class is added is a rule that is eventually forgotten - the same reasoning
 * `Seller::scopePublic` gives for carrying a condition inside the query.
 *
 * This covers nothing else. An exception that means something is broken still
 * reports, and should: that is the difference between a marketplace saying no
 * and a marketplace failing.
 */
abstract class DomainRefusal extends RuntimeException implements ShouldntReport
{
    //
}
