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
     * $entityType narrows the read to commits on that entity type. It is an
     * optimization, not a filter the caller may rely on for correctness: a
     * store that cannot narrow, or a commit whose type it never recorded, must
     * return the commit and let the caller decide. Without it a view matching
     * one type of a busy tenant pays to decode every other type's writes on
     * every poll, per device.
     *
     * Narrowing must not change $limit's meaning: the budget counts commits
     * returned, so a caller's hasMore is still answered by asking for one more.
     *
     * @return list<Commit>
     *
     * @throws HistoryUnavailable when $after is below the retention horizon
     */
    public function commitsAfter(string $space, int $after, int $limit, ?string $entityType = null): array;

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

    /**
     * Drop commits below $from, and raise the space's retention horizon to it.
     *
     * The log is the only thing here that grows without bound, and a host given
     * this contract had no way to reach the pruning both shipped adapters
     * already implemented - so there was no supported way to stop a busy tenant
     * filling the disk. It belongs on the contract for that reason.
     *
     * A reader whose cursor falls below the new horizon is told to rebuild
     * rather than served a gap: prune only past what every device has already
     * acknowledged, or accept that the slow ones re-bootstrap.
     *
     * Acknowledgement rows are NOT pruned by this. They are what makes a
     * replayed mutation safe, and one is kept per replica per space.
     */
    public function prune(string $space, CommitSequence $from): void;
}
