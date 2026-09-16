<?php

declare(strict_types=1);

namespace Cbox\Sync\Contracts;

use Cbox\Sync\Data\Commit;
use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\PullPage;
use Cbox\Sync\Data\Receipt;
use Cbox\Sync\Data\RecordCriteria;
use Cbox\Sync\Exceptions\HistoryUnavailable;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Replica;

interface Store
{
    /**
     * Runs one transaction against a single space. The callback receives a
     * ledger whose reads are keyed; publication must be atomic, all stored
     * objects immutable, and writers in the same space serialized consistently
     * with feed order.
     *
     * @template TResult
     *
     * @param  \Closure(Ledger): TResult  $callback
     * @return TResult
     */
    public function transaction(string $space, \Closure $callback): mixed;

    public function pull(string $space, int $after = 0, int $limit = 100): PullPage;

    /**
     * Commits in the space ordered by sequence, strictly after $after, at most
     * $limit of them. A commit budget, unlike pull()'s change budget.
     *
     * @return list<Commit>
     *
     * @throws HistoryUnavailable when $after is below the retention horizon
     */
    public function commitsAfter(string $space, int $after, int $limit): array;

    /**
     * Live records in the space, excluding tombstones, ordered by entity type
     * then id in byte order, starting strictly after $after.
     *
     * Criteria may only narrow which rows are read; the caller decides
     * membership itself. An implementation that cannot narrow by the given
     * criteria must throw rather than scan the whole space.
     *
     * @return list<EntityRecord>
     */
    public function scanRecords(string $space, ?EntityKey $after, int $limit, ?RecordCriteria $criteria = null): array;

    public function record(EntityKey $entity): ?EntityRecord;

    public function receipt(string $mutationId): ?Receipt;

    public function group(string $id): ?ConflictGroup;

    /** @return list<ConflictGroup> */
    public function openGroups(EntityKey $entity): array;

    public function acknowledged(string $space, Replica $replica): int;

    /** Highest committed sequence in the space; zero when it has none. */
    public function watermark(string $space): CommitSequence;

    /** Lowest retained sequence: one when nothing has been pruned, zero when the space is empty. */
    public function retainedFrom(string $space): CommitSequence;
}
