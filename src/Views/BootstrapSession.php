<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\ValueObjects\CommitSequence;

/** @internal In-memory frozen snapshot retained for retriable continuation tokens. */
readonly class BootstrapSession
{
    /** @var list<EntityRecord> */
    public array $records;

    /** @param list<EntityRecord> $records */
    public function __construct(public CursorContext $context, array $records, public CommitSequence $watermark, public int $pageSize)
    {
        $copy = [];
        foreach ($records as $record) {
            $copy[] = $record;
        }
        $this->records = $copy;
    }
}
