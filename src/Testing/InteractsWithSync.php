<?php

declare(strict_types=1);

namespace Cbox\Sync\Testing;

use Cbox\Sync\Contracts\ConflictResolver;
use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Resolvers\PreserveConflict;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

trait InteractsWithSync
{
    protected InMemoryStore $store;

    protected Engine $engine;

    protected EntityKey $key;

    protected function setUpSync(ConflictResolver $resolver = new PreserveConflict): void
    {
        $this->store = new InMemoryStore;
        $this->engine = new Engine($this->store, $resolver, new FakeIdGenerator);
        $this->key = new EntityKey('test', 'notes', 'one');
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
        return $this->store->snapshot()->records[$this->key->key()] ?? throw new \LogicException('No record in fixture');
    }

    /** @return list<ConflictGroup> */
    protected function openConflicts(): array
    {
        return array_values(array_filter($this->store->snapshot()->groups, fn (ConflictGroup $group): bool => $group->entity->key() === $this->key->key() && $group->isOpen()));
    }
}
