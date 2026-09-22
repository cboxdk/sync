<?php

declare(strict_types=1);

namespace Cbox\Sync\Persistence\Pdo;

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

/**
 * One database transaction. Read-your-own-writes comes from the transaction
 * itself, and the draft is a real savepoint, so a validator sharing this
 * connection sees staged writes exactly as the engine does.
 */
class PdoLedger implements Ledger
{
    private const DRAFT = 'sync_draft';

    /** @var array<string, true> */
    private array $changedRecords = [];

    /** @var list<string> Group IDs in first-touch order */
    private array $touched = [];

    /** @var array{array<string, true>, list<string>}|null */
    private ?array $savepoint = null;

    public function __construct(private \PDO $connection, private PdoSchema $schema, private string $space) {}

    public function space(): string
    {
        return $this->space;
    }

    public function receipt(string $mutationId): ?Receipt
    {
        $payload = $this->scalar('SELECT payload FROM sync_receipts WHERE mutation_id = ?', [$mutationId]);

        return $payload === null ? null : Payload::decode($payload, Receipt::class);
    }

    public function prunedThrough(Replica $replica): int
    {
        $value = $this->scalar('SELECT pruned_through FROM sync_streams WHERE space = ? AND replica_id = ?', [$this->space, $replica->id]);

        return $value === null ? 0 : (int) $value;
    }

    public function acknowledged(Replica $replica): int
    {
        $value = $this->scalar('SELECT acknowledged FROM sync_streams WHERE space = ? AND replica_id = ?', [$this->space, $replica->id]);

        return $value === null ? 0 : (int) $value;
    }

    public function record(EntityKey $entity): ?EntityRecord
    {
        $payload = $this->scalar('SELECT payload FROM sync_records WHERE space = ? AND entity_type = ? AND entity_id = ?', [$entity->space, $entity->type, $entity->id]);

        return $payload === null ? null : Payload::decode($payload, EntityRecord::class);
    }

    public function group(string $id): ?ConflictGroup
    {
        $payload = $this->scalar('SELECT payload FROM sync_conflict_groups WHERE id = ?', [$id]);

        return $payload === null ? null : Payload::decode($payload, ConflictGroup::class);
    }

    public function openGroup(EntityKey $entity, string $field): ?ConflictGroup
    {
        $payload = $this->scalar('SELECT payload FROM sync_conflict_groups WHERE open_key = ?', [
            $this->schema->openKey($entity->space, $entity->type, $entity->id, $field),
        ]);

        return $payload === null ? null : Payload::decode($payload, ConflictGroup::class);
    }

    public function watermark(): CommitSequence
    {
        $value = $this->scalar('SELECT commit_sequence FROM sync_spaces WHERE space = ?', [$this->space]);

        return new CommitSequence($value === null ? 0 : (int) $value);
    }

    public function putRecord(EntityRecord $record): void
    {
        $entity = $record->entity;
        $this->run('DELETE FROM sync_records WHERE space = ? AND entity_type = ? AND entity_id = ?', [$entity->space, $entity->type, $entity->id]);
        $this->run('INSERT INTO sync_records (space, entity_type, entity_id, version, deleted, payload) VALUES (?, ?, ?, ?, ?, ?)', [
            $entity->space, $entity->type, $entity->id, $record->version->value, $record->deleted ? 1 : 0, Payload::encode($record),
        ]);
        $this->run('DELETE FROM sync_fields WHERE space = ? AND entity_type = ? AND entity_id = ?', [$entity->space, $entity->type, $entity->id]);
        foreach ($record->fields as $field => $state) {
            $this->run('INSERT INTO sync_fields (space, entity_type, entity_id, field, value_hash) VALUES (?, ?, ?, ?, ?)', [
                $entity->space, $entity->type, $entity->id, $field, Payload::fieldHash($state->value),
            ]);
        }
        $this->changedRecords[$entity->key()] = true;
    }

    public function putGroup(ConflictGroup $group): void
    {
        $entity = $group->entity;
        $this->run('DELETE FROM sync_conflict_groups WHERE id = ?', [$group->id]);
        $this->run('INSERT INTO sync_conflict_groups (id, space, entity_type, entity_id, field, revision, open_key, payload) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $group->id, $entity->space, $entity->type, $entity->id, $group->field, $group->revision,
            $group->isOpen() ? $this->schema->openKey($entity->space, $entity->type, $entity->id, $group->field) : null,
            Payload::encode($group),
        ]);
        if (! in_array($group->id, $this->touched, true)) {
            $this->touched[] = $group->id;
        }
    }

    public function putReceipt(Receipt $receipt): void
    {
        try {
            $this->run('INSERT INTO sync_receipts (mutation_id, space, commit_sequence, payload) VALUES (?, ?, ?, ?)', [
                $receipt->mutation->id, $this->space, $receipt->result->commitSequence?->value, Payload::encode($receipt),
            ]);
        } catch (\PDOException $exception) {
            // Only a genuine duplicate. Catching every PDOException here
            // reported a deadlock, a dropped connection, a too-long identifier
            // and a missing table all as "already processed" - and a caller
            // that trusts that answer drops the write and reports success.
            //
            // Mutation identity is global, so the space lock cannot prevent a
            // real duplicate.
            if (! in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            throw new TransientFailure('Mutation identity already recorded', previous: $exception);
        }
    }

    public function acknowledge(Replica $replica, int $sequence): void
    {
        $this->run('DELETE FROM sync_streams WHERE space = ? AND replica_id = ?', [$this->space, $replica->id]);
        $this->run('INSERT INTO sync_streams (space, replica_id, acknowledged) VALUES (?, ?, ?)', [$this->space, $replica->id, $sequence]);
    }

    public function appendCommit(CommitSequence $sequence, array $changes): Commit
    {
        if ($sequence->value !== $this->watermark()->value + 1) {
            throw new ProtocolException('Commit sequence must be the watermark plus one');
        }
        $commit = new Commit($this->space, $sequence, $changes);
        $this->run(
            'INSERT INTO sync_commits (space, sequence, entity_type, payload) VALUES (?, ?, ?, ?)',
            [$this->space, $sequence->value, $commit->entityType(), Payload::encode($commit)],
        );
        $this->run('UPDATE sync_spaces SET commit_sequence = ? WHERE space = ?', [$sequence->value, $this->space]);

        return $commit;
    }

    public function beginDraft(): void
    {
        $this->connection->exec('SAVEPOINT '.self::DRAFT);
        $this->savepoint = [$this->changedRecords, $this->touched];
    }

    public function commitDraft(): void
    {
        if ($this->savepoint === null) {
            throw new \LogicException('No draft to commit');
        }
        $this->connection->exec('RELEASE SAVEPOINT '.self::DRAFT);
        $this->savepoint = null;
    }

    public function rollbackDraft(): void
    {
        if ($this->savepoint === null) {
            throw new \LogicException('No draft to roll back');
        }
        $this->connection->exec('ROLLBACK TO SAVEPOINT '.self::DRAFT);
        $this->connection->exec('RELEASE SAVEPOINT '.self::DRAFT);
        [$this->changedRecords, $this->touched] = $this->savepoint;
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
            $group = $this->group($id);
            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /** @param list<string|int|null> $bindings */
    private function scalar(string $sql, array $bindings): ?string
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($bindings);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @param list<string|int|null> $bindings */
    private function run(string $sql, array $bindings): void
    {
        $this->connection->prepare($sql)->execute($bindings);
    }
}
