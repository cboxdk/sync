<?php

declare(strict_types=1);

namespace Cbox\Sync\Contracts;

use Cbox\Sync\ValueObjects\CommitSequence;

/**
 * Told that a space advanced, after the write is durable.
 *
 * This exists so devices do not have to poll to find out. It carries the
 * watermark and NOTHING ELSE, on purpose: the log is per space, but
 * authorization is per principal and per view. Putting the changes themselves
 * in the signal would hand every listener on a space everything written in it,
 * including the rows and fields a given reader is not allowed to see. The
 * signal says "there is something new, up to here"; the reader then asks
 * through the endpoint that knows who it is.
 *
 * Called after the transaction commits, never inside it - a rollback must not
 * announce a write that did not happen. A throw here cannot unmake that commit,
 * so the engine does not let it reach the caller either: failing a push for a
 * mutation that is durably stored would only make the client retry, meet its
 * own receipt, and be told the same thing again. Reporting the failure is this
 * implementation's job - it is the half that has a logger.
 *
 * Delivery is at-most-once and unordered. A missed signal must therefore never
 * mean missed data, which is why a reader still polls on a slow timer: the
 * signal makes sync prompt, the cursor makes it correct.
 */
interface CommitObserver
{
    public function committed(string $space, CommitSequence $watermark): void;
}
