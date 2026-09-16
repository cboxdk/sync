<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\ValueObjects\CommitSequence;

readonly class ViewCursor
{
    public function __construct(public CursorContext $context, public CommitSequence $position = new CommitSequence) {}
}
