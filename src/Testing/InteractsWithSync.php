<?php

declare(strict_types=1);

namespace Cbox\Sync\Testing;

use Cbox\Sync\Contracts\ConflictResolver;
use Cbox\Sync\Contracts\Inspectable;
use Cbox\Sync\Contracts\Ledger;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\Commit;
use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Persistence\Pdo\PdoStore;
use Cbox\Sync\Resolvers\PreserveConflict;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

trait InteractsWithSync
{
    protected Store $store;

    protected Engine $engine;

    protected EntityKey $key;

    protected function setUpSync(ConflictResolver $resolver = new PreserveConflict): void
    {
        $this->store = $this->syncStore();
        $this->engine = new Engine($this->store, $resolver, new FakeIdGenerator);
        $this->key = new EntityKey('test', 'notes', 'one');
    }

    /**
     * SYNC_STORE selects what the suite runs against: `memory` (default),
     * `rehydrating` for a store that shares no objects across commits, or
     * `sqlite` for the durable PDO adapter.
     */
    protected function syncStore(): Store
    {
        return match (getenv('SYNC_STORE') ?: 'memory') {
            'rehydrating' => new RehydratingStore,
            'sqlite' => self::pdoStore('sqlite::memory:'),
            'pdo' => self::pdoStore((string) (getenv('SYNC_DSN') ?: ''), (string) (getenv('SYNC_DB_USER') ?: ''), (string) (getenv('SYNC_DB_PASSWORD') ?: '')),
            default => new InMemoryStore,
        };
    }

    /** A schema-fresh durable store. A shared server is reset per test, which a private SQLite database does not need. */
    protected static function pdoStore(string $dsn, string $user = '', string $password = ''): PdoStore
    {
        if ($dsn === '') {
            throw new \LogicException('SYNC_DSN must be set to run the suite against a database');
        }
        $connection = new \PDO($dsn, $user === '' ? null : $user, $password === '' ? null : $password);
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if (! str_starts_with($dsn, 'sqlite:')) {
            foreach (['sync_commits', 'sync_conflict_groups', 'sync_fields', 'sync_records', 'sync_receipts', 'sync_streams', 'sync_spaces'] as $table) {
                $connection->exec('DROP TABLE IF EXISTS '.$table);
            }
        }
        $store = new PdoStore($connection);
        $store->migrate();

        return $store;
    }

    protected function seedRecord(): void
    {
        $this->engine->process($this->mutation('seed', 1, [FieldOperation::set('title', 'initial'), FieldOperation::set('body', 'body')], 0, kind: MutationKind::Create));
    }

    /** @param list<FieldOperation> $operations */
    protected function mutation(string $replica, int $sequence, array $operations, int $base = 1, bool $atomic = true, ?string $dependsOn = null, MutationKind $kind = MutationKind::Update, ?Resolution $resolution = null, ?string $id = null, ?RecordVersion $expectedVersion = null): Mutation
    {
        return new Mutation($id ?? $replica.'-'.$sequence, $this->key, new Replica($replica), new MutationSequence($sequence), $kind, new RecordVersion($base), $operations, $atomic, $dependsOn, $resolution, $expectedVersion);
    }

    /** @param list<FieldOperation> $operations */
    protected function write(string $replica, int $sequence, array $operations, int $base = 1, bool $atomic = true, ?string $dependsOn = null): MutationResult
    {
        return $this->engine->process($this->mutation($replica, $sequence, $operations, $base, $atomic, $dependsOn));
    }

    protected function record(): EntityRecord
    {
        return $this->store->record($this->key) ?? throw new \LogicException('No record in fixture');
    }

    /**
     * Runs one transaction against the fixture space.
     *
     * @template TResult
     *
     * @param  \Closure(Ledger): TResult  $callback
     * @return TResult
     */
    protected function inTransaction(\Closure $callback): mixed
    {
        return $this->store->transaction($this->key->space, $callback);
    }

    /** @return list<Commit> */
    protected function commits(): array
    {
        $commits = [];
        $cursor = 0;
        do {
            $page = $this->store->pull($this->key->space, $cursor, 100);
            foreach ($page->commits as $commit) {
                $commits[] = $commit;
            }
            $cursor = $page->nextCursor->value;
        } while ($page->hasMore);

        return $commits;
    }

    protected function commit(int $sequence): Commit
    {
        foreach ($this->commits() as $commit) {
            if ($commit->sequence->value === $sequence) {
                return $commit;
            }
        }

        throw new \LogicException('No commit '.$sequence.' in fixture');
    }

    /**
     * A value that must not change when a transaction rolls back. An
     * inspectable store compares its whole state, which also proves nothing
     * about object sharing moved; any other store compares everything the
     * contract exposes.
     */
    protected function storeDigest(): string
    {
        if ($this->store instanceof Inspectable) {
            return serialize($this->store->snapshot());
        }

        return serialize([
            $this->store->watermark($this->key->space),
            $this->store->retainedFrom($this->key->space),
            $this->commits(),
            $this->store->record($this->key),
            $this->store->openGroups($this->key),
        ]);
    }

    protected function lastCommit(): Commit
    {
        $commits = $this->commits();

        return $commits[array_key_last($commits)] ?? throw new \LogicException('No commit in fixture');
    }

    /** @return list<ConflictGroup> */
    protected function openConflicts(): array
    {
        return $this->store->openGroups($this->key);
    }
}
