<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\ValueObjects\CommitSequence;

/**
 * A mutation's answer, and whether THIS call is the one that wrote it.
 *
 * A replay is answered with the stored result, which is identical to the
 * first answer - so a host that does something of its own for a write that
 * landed, writing its table say, cannot tell the two apart from the result.
 * Two identical requests racing each other both missed the receipt before
 * the lock, and the second repeated that work. The commit this call appended
 * says which one wrote it.
 */
readonly class Submission
{
    public function __construct(
        public MutationResult $result,
        public ?CommitSequence $committed = null,
    ) {}

    /**
     * Whether this call appended a commit - false for a replay, a gap, a
     * refusal to store anything. A rejection appends one too (its receipt), so
     * a host doing work for a write that landed checks the status as well.
     */
    public function wrote(): bool
    {
        return $this->committed !== null;
    }
}
