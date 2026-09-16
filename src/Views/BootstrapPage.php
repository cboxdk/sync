<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Exceptions\InvalidRequest;

readonly class BootstrapPage
{
    /** @var list<EntityRecord> */
    public array $records;

    /** @param list<EntityRecord> $records */
    public function __construct(
        array $records,
        public ?BootstrapToken $nextToken,
        public ?ViewCursor $cursor,
        public CursorContext $context,
        public BootstrapToken $token,
        public int $offset,
    ) {
        $copy = [];
        foreach ($records as $record) {
            $copy[] = $record;
        }
        $this->records = $copy;

        if ($offset < 0 || ($cursor !== null && $cursor->context->fingerprint() !== $context->fingerprint())) {
            throw new InvalidRequest('Invalid bootstrap page position or cursor context');
        }
    }

    public function isComplete(): bool
    {
        return $this->cursor !== null;
    }
}
