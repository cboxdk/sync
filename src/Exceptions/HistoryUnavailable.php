<?php

declare(strict_types=1);

namespace Cbox\Sync\Exceptions;

use Cbox\Sync\ValueObjects\CommitSequence;

/** The requested cursor is below the retention horizon: the history it asks for no longer exists. */
class HistoryUnavailable extends ProtocolException
{
    public function __construct(public readonly string $space, public readonly CommitSequence $cursor, public readonly CommitSequence $retainedFrom)
    {
        parent::__construct('History before commit '.$retainedFrom->value.' in space '.$space.' has been pruned');
    }
}
