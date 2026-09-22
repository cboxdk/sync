<?php

declare(strict_types=1);

namespace Cbox\Sync\Persistence;

use Cbox\Sync\Contracts\Inspectable;
use Cbox\Sync\Contracts\Ledger;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\Commit;
use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\PullPage;
use Cbox\Sync\Data\Receipt;
use Cbox\Sync\Data\RecordCriteria;
use Cbox\Sync\Exceptions\HistoryUnavailable;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Replica;

class InMemoryStore implements Inspectable, Store
{
    private State $state;

    private bool $active = false;

    public function __construct()
    {
        $this->state = new State;
    }

    public function transaction(string $space, \Closure $callback): mixed
    {
        if ($space === '') {
            throw new InvalidRequest('Transaction space must not be empty');
        }
        if ($this->active) {
            throw new TransientFailure('Nested or concurrent transaction is unsupported');
        }
        $this->active = true;
        $workspace = clone $this->state;
        try {
            $result = $callback($this->ledger($workspace, $space));
            $this->beforeCommit($space);
            $this->beforePublish($workspace);
            $this->state = clone $workspace;

            return $result;
        } finally {
            $this->active = false;
        }
    }

    protected function ledger(State $workspace, string $space): Ledger
    {
        return new InMemoryLedger($workspace, $space);
    }

    /**
     * Adapter hook; failure here rolls back even results and acknowledgements.
     *
     * Deliberately the same signature the durable adapter offers. They differed
     * before, so a fault-injecting store could only extend this one - and the
     * rollback test, which proves the most important durability property here,
     * had never run against a database on any driver.
     */
    protected function beforeCommit(string $space): void {}

    /**
     * The staged workspace, immediately before it becomes the published state.
     *
     * Only this adapter has one to offer, which is why it is separate from the
     * hook above rather than folded into it.
     */
    protected function beforePublish(State $workspace): void {}

    public function snapshot(): State
    {
        return clone $this->state;
    }

    public function commitsAfter(string $space, int $after, int $limit, ?string $entityType = null): array
    {
        if ($after < 0 || $limit < 1) {
            throw new InvalidRequest('Invalid commit cursor or budget');
        }
        $retainedFrom = $this->retainedFrom($space)->value;
        if ($retainedFrom > 0 && $after < $retainedFrom - 1) {
            throw new HistoryUnavailable($space, new CommitSequence($after), new CommitSequence($retainedFrom));
        }
        $commits = [];
        foreach ($this->state->commits[$space] ?? [] as $commit) {
            if ($commit->sequence->value <= $after) {
                continue;
            }
            if (count($commits) === $limit) {
                break;
            }
            // Null means the type is unknown for this commit, which is not the
            // same as "does not match": it still has to be delivered.
            $type = $commit->entityType();
            if ($entityType !== null && $type !== null && $type !== $entityType) {
                continue;
            }
            $commits[] = $commit;
        }

        return $commits;
    }

    public function scanRecords(string $space, ?EntityKey $after, int $limit, ?RecordCriteria $criteria = null): array
    {
        if ($limit < 1) {
            throw new InvalidRequest('Invalid scan limit');
        }
        $records = [];
        foreach ($this->state->records as $record) {
            if ($record->entity->space !== $space || $record->deleted) {
                continue;
            }
            if ($criteria !== null && ! $criteria->matches($record)) {
                continue;
            }
            if ($after !== null && self::compareKeys($record->entity, $after) <= 0) {
                continue;
            }
            $records[] = $record;
        }
        usort($records, fn (EntityRecord $left, EntityRecord $right): int => self::compareKeys($left->entity, $right->entity));

        return array_slice($records, 0, $limit);
    }

    /**
     * Byte order, which is what the contract promises and what every driver's
     * binary collation gives.
     *
     * Not PHP's array comparison: it compares two numeric strings NUMERICALLY,
     * so '9' sorts before '10' where a database puts it after, and '1e2' and
     * '100' compare EQUAL - which makes the keyset filter below treat one of
     * two distinct records as already passed and drop it from the page, while
     * the page still reports itself finished.
     */
    private static function compareKeys(EntityKey $left, EntityKey $right): int
    {
        $byType = strcmp($left->type, $right->type);

        return $byType !== 0 ? $byType : strcmp($left->id, $right->id);
    }

    public function record(EntityKey $entity): ?EntityRecord
    {
        return $this->state->records[$entity->key()] ?? null;
    }

    public function receipt(string $mutationId): ?Receipt
    {
        return $this->state->receipts[$mutationId] ?? null;
    }

    public function group(string $id): ?ConflictGroup
    {
        return $this->state->groups[$id] ?? null;
    }

    public function openGroups(EntityKey $entity): array
    {
        $groups = [];
        foreach ($this->state->groups as $group) {
            if ($group->entity->key() === $entity->key() && $group->isOpen()) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    public function acknowledged(string $space, Replica $replica): int
    {
        return $this->state->acknowledged[$replica->stream($space)] ?? 0;
    }

    public function watermark(string $space): CommitSequence
    {
        return new CommitSequence($this->state->watermark[$space] ?? 0);
    }

    public function retainedFrom(string $space): CommitSequence
    {
        if (($this->state->watermark[$space] ?? 0) === 0) {
            return new CommitSequence;
        }

        return new CommitSequence($this->state->retainedFrom[$space] ?? 1);
    }

    /** Drops every commit below $from. Cursors behind the new horizon can only be recovered by a bootstrap. */
    public function prune(string $space, CommitSequence $from): void
    {
        $retained = [];
        foreach ($this->state->commits[$space] ?? [] as $commit) {
            if ($commit->sequence->value >= $from->value) {
                $retained[] = $commit;
            }
        }
        $this->state->commits[$space] = $retained;
        foreach ($this->state->receipts as $id => $receipt) {
            $sequence = $receipt->result->commitSequence;
            if ($sequence !== null && $sequence->value < $from->value && $this->receiptSpace($receipt) === $space) {
                $stream = $receipt->mutation->replica->stream($space);
                $this->state->prunedThrough[$stream] = max($this->state->prunedThrough[$stream] ?? 0, $receipt->mutation->sequence->value);
                unset($this->state->receipts[$id]);
            }
        }
        $this->state->retainedFrom[$space] = max($this->state->retainedFrom[$space] ?? 1, $from->value);
    }

    private function receiptSpace(Receipt $receipt): string
    {
        return $receipt->mutation->entity->space;
    }

    public function pull(string $space, int $after = 0, int $limit = 100): PullPage
    {
        $watermark = $this->watermark($space)->value;
        if ($after < 0 || $after > $watermark || $limit < 1) {
            throw new InvalidRequest('Invalid pull cursor or limit');
        }
        $retainedFrom = $this->retainedFrom($space)->value;
        if ($after < $retainedFrom - 1) {
            throw new HistoryUnavailable($space, new CommitSequence($after), new CommitSequence($retainedFrom));
        }
        $page = [];
        $size = 0;
        $cursor = $after;
        foreach ($this->state->commits[$space] ?? [] as $commit) {
            if ($commit->sequence->value <= $after) {
                continue;
            }
            if ($page !== [] && $size + count($commit->changes) > $limit) {
                break;
            }
            $page[] = $commit;
            $size += count($commit->changes);
            $cursor = $commit->sequence->value;
        }

        return new PullPage($page, new CommitSequence($cursor), $cursor < $watermark);
    }
}
