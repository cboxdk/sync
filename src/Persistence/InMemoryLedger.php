<?php

declare(strict_types=1);

namespace Cbox\Sync\Persistence;

use Cbox\Sync\Contracts\Ledger;
use Cbox\Sync\Data\Commit;
use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\Receipt;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Replica;

/** Reference ledger over a transaction workspace. The draft layer is an array copy; a durable adapter uses a savepoint. */
class InMemoryLedger implements Ledger
{
    /** @var array<string, true> */
    private array $changedRecords = [];

    /** @var list<string> Group IDs in first-touch order */
    private array $touched = [];

    /** @var array{array<string, EntityRecord>, array<string, ConflictGroup>, array<string, true>, list<string>}|null */
    private ?array $savepoint = null;

    public function __construct(private State $state, private string $space) {}

    public function space(): string
    {
        return $this->space;
    }

    public function receipt(string $mutationId, bool $latest = false): ?Receipt
    {
        return $this->state->receipts[$mutationId] ?? null;
    }

    public function prunedThrough(Replica $replica): int
    {
        return $this->state->prunedThrough[$replica->stream($this->space)] ?? 0;
    }

    public function acknowledged(Replica $replica): int
    {
        return $this->state->acknowledged[$replica->stream($this->space)] ?? 0;
    }

    public function record(EntityKey $entity): ?EntityRecord
    {
        return $this->state->records[$entity->key()] ?? null;
    }

    public function group(string $id): ?ConflictGroup
    {
        return $this->state->groups[$id] ?? null;
    }

    public function openGroup(EntityKey $entity, string $field): ?ConflictGroup
    {
        foreach ($this->state->groups as $group) {
            if ($group->entity->key() === $entity->key() && $group->field === $field && $group->isOpen()) {
                return $group;
            }
        }

        return null;
    }

    public function watermark(): CommitSequence
    {
        return new CommitSequence($this->state->watermark[$this->space] ?? 0);
    }

    public function putRecord(EntityRecord $record): void
    {
        $this->state->records[$record->entity->key()] = $record;
        $this->changedRecords[$record->entity->key()] = true;
    }

    public function putGroup(ConflictGroup $group): void
    {
        $this->state->groups[$group->id] = $group;
        if (! in_array($group->id, $this->touched, true)) {
            $this->touched[] = $group->id;
        }
    }

    public function putReceipt(Receipt $receipt): void
    {
        if (isset($this->state->receipts[$receipt->mutation->id])) {
            throw new TransientFailure('Mutation identity already recorded');
        }
        $this->state->receipts[$receipt->mutation->id] = $receipt;
    }

    public function amendReceipt(Receipt $receipt): void
    {
        if (isset($this->state->receipts[$receipt->mutation->id])) {
            $this->state->receipts[$receipt->mutation->id] = $receipt;
        }
    }

    public function acknowledge(Replica $replica, int $sequence): void
    {
        $this->state->acknowledged[$replica->stream($this->space)] = $sequence;
    }

    public function appendCommit(CommitSequence $sequence, array $changes): Commit
    {
        if ($sequence->value !== $this->watermark()->value + 1) {
            throw new ProtocolException('Commit sequence must be the watermark plus one');
        }
        $commit = new Commit($this->space, $sequence, $changes);
        $this->state->commits[$this->space][] = $commit;
        $this->state->watermark[$this->space] = $sequence->value;

        return $commit;
    }

    public function beginDraft(): void
    {
        $this->savepoint = [$this->state->records, $this->state->groups, $this->changedRecords, $this->touched];
    }

    public function commitDraft(): void
    {
        // The same refusal rollbackDraft gives. Silently accepting it here let
        // a ledger bug through on one adapter and raised a raw PDOException on
        // the other - which on PostgreSQL also poisons the whole transaction.
        if ($this->savepoint === null) {
            throw new \LogicException('No draft to commit');
        }
        $this->savepoint = null;
    }

    public function rollbackDraft(): void
    {
        if ($this->savepoint === null) {
            throw new \LogicException('No draft to roll back');
        }
        [$this->state->records, $this->state->groups, $this->changedRecords, $this->touched] = $this->savepoint;
        $this->savepoint = null;
    }

    public function recordChanged(EntityKey $entity): bool
    {
        return isset($this->changedRecords[$entity->key()]);
    }

    public function touchedGroups(): array
    {
        $groups = [];
        foreach ($this->touched as $id) {
            $group = $this->state->groups[$id] ?? null;
            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
