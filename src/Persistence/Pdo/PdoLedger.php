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

    public function __construct(private \PDO $connection, private PdoSchema $schema, private string $space, private bool $lockingReads = false) {}

    public function space(): string
    {
        return $this->space;
    }

    public function receipt(string $mutationId): ?Receipt
    {
        $payload = $this->scalar('SELECT payload FROM sync_receipts WHERE mutation_id = ?', [$mutationId]);

        return $payload === null ? null : Payload::decode($payload, Receipt::class);
    }

    public function receiptAt(Replica $replica, int $sequence): ?Receipt
    {
        $sql = 'SELECT payload FROM sync_receipts WHERE space = ? AND replica_id = ? AND sequence = ?';
        if ($this->lockingReads && $this->schema->driver === PdoSchema::MYSQL) {
            // A locking read sees the latest committed row, whatever snapshot
            // the host's transaction took before the space lock. An equality
            // match on a unique index: a row that exists is locked alone. It
            // is only asked for a position the stream has used and not pruned,
            // so it exists; a range read here locked neighbouring tenants'
            // gaps and deadlocked two of them.
            $sql .= ' FOR SHARE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([$this->space, $replica->id, $sequence]);
        $value = $statement->fetchColumn();

        return is_string($value) ? Payload::decode($value, Receipt::class) : null;
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
        // The identity first: a duplicate of it collides with its position as
        // well, and SQLite reports whichever constraint it checks first.
        if ($this->scalar('SELECT 1 FROM sync_receipts WHERE mutation_id = ?', [$receipt->mutation->id]) !== null) {
            throw new TransientFailure('Mutation identity already recorded');
        }
        try {
            $this->run('INSERT INTO sync_receipts (mutation_id, space, commit_sequence, replica_id, sequence, payload) VALUES (?, ?, ?, ?, ?, ?)', [
                $receipt->mutation->id, $this->space, $receipt->result->commitSequence?->value,
                $receipt->mutation->replica->id, $receipt->mutation->sequence->value, Payload::encode($receipt),
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
            // By the constraint's name, never the whole message: MySQL and
            // PostgreSQL quote the duplicate value, which a client chooses.
            if (preg_match('/key \'[^\']*sync_receipts_stream_position\'|constraint "sync_receipts_stream_position"|failed: sync_receipts\.space, sync_receipts\.replica_id, sync_receipts\.sequence/', $exception->getMessage()) === 1) {
                // The position, not the identity: this stream's acknowledged
                // sequence and its receipts disagree - a partial restore, a
                // hand edit. Sending again meets the same row for ever, so it
                // is not worth retrying; it needs someone to look.
                throw new \LogicException(sprintf('Stream %s in space %s already has an answer at position %d although it is acknowledged below it: the stream and its receipts disagree.', $receipt->mutation->replica->id, $this->space, $receipt->mutation->sequence->value), previous: $exception);
            }

            throw new TransientFailure('Mutation identity already recorded', previous: $exception);
        }
    }

    public function amendReceipt(Receipt $receipt): void
    {
        $this->run('UPDATE sync_receipts SET payload = ? WHERE mutation_id = ? AND space = ?', [Payload::encode($receipt), $receipt->mutation->id, $this->space]);
    }

    public function acknowledge(Replica $replica, int $sequence): void
    {
        // In place, not delete-and-insert: the row also carries how far this
        // stream's receipts were pruned, and replacing it reset that to zero -
        // after which a pruned replay was renumbered and applied twice.
        if ($this->scalar('SELECT 1 FROM sync_streams WHERE space = ? AND replica_id = ?', [$this->space, $replica->id]) === null) {
            $this->run('INSERT INTO sync_streams (space, replica_id, acknowledged) VALUES (?, ?, ?)', [$this->space, $replica->id, $sequence]);

            return;
        }
        $this->run('UPDATE sync_streams SET acknowledged = ? WHERE space = ? AND replica_id = ?', [$sequence, $this->space, $replica->id]);
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

    /**
     * Every read inside the ledger sees the latest committed state.
     *
     * The store opens its own transactions at READ COMMITTED on MySQL, which
     * gives that on its own. Inside a host's transaction it cannot choose the
     * isolation, and under REPEATABLE READ the snapshot is fixed at the host's
     * first read - before the space lock - so the ledger numbered its commit
     * from before another writer committed. Locking reads see the latest
     * version there. PostgreSQL reads the latest committed row per statement
     * already; SQLite has one writer.
     *
     * @param  list<string|int|null>  $bindings
     */
    private function scalar(string $sql, array $bindings): ?string
    {
        // Receipts are global, keyed by mutation id alone: a locking read that
        // finds nothing there locks a gap other spaces insert into. A duplicate
        // id is caught by the primary key instead.
        if ($this->lockingReads && $this->schema->driver === PdoSchema::MYSQL && str_starts_with($sql, 'SELECT') && ! str_contains($sql, 'FROM sync_receipts')) {
            $sql .= ' FOR SHARE';
        }
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
