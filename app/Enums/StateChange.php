<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What applying a Pixelforge generation state did to our row. Logged for every webhook and reconciliation.
 */
enum StateChange: string
{
    /** The status moved forward. */
    case Applied = 'applied';

    /** The event was older than what we already have (or the same status), so nothing changed. */
    case Stale = 'stale';

    /** Pixelforge billed a generation we had already failed locally; we record its cost but do not charge the user. */
    case BilledAfterLocalFailure = 'billed_after_local_failure';

    /** Not one of our generations (another environment, or no matching row). */
    case Unknown = 'unknown';

    /** Nothing to apply: a ping, or an event type we do not act on. */
    case Ignored = 'ignored';

    /** The same event id was already processed. */
    case Duplicate = 'duplicate';
}
