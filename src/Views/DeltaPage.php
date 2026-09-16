<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Exceptions\InvalidRequest;

readonly class DeltaPage
{
    /** @var list<ViewCommit> */
    public array $commits;

    /** @param list<ViewCommit> $commits */
    public function __construct(array $commits, public ViewCursor $previousCursor, public ViewCursor $cursor, public bool $hasMore)
    {
        $copy = [];
        foreach ($commits as $commit) {
            $copy[] = $commit;
        }
        $this->commits = $copy;

        if ($previousCursor->context->fingerprint() !== $cursor->context->fingerprint() || $previousCursor->position->value > $cursor->position->value) {
            throw new InvalidRequest('Invalid delta cursor range');
        }
    }
}
