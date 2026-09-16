<?php

declare(strict_types=1);

namespace Cbox\Sync\Tests\Fixtures;

use Cbox\Sync\Data\AdapterContext;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

class ViewScenario
{
    private int $nextMutation = 1;

    public function __construct(public InMemoryStore $store = new InMemoryStore, public ?Engine $engine = null)
    {
        $this->engine ??= new Engine($this->store);
    }

    public function create(string $id, string $project, string $status = 'open', ?string $actor = null): EntityKey
    {
        $entity = new EntityKey('test', 'items', $id);
        $this->process($entity, MutationKind::Create, [
            FieldOperation::set('project', $project),
            FieldOperation::set('status', $status),
            FieldOperation::set('title', 'title-'.$id),
        ], 0, $actor);

        return $entity;
    }

    /** @param list<FieldOperation> $operations */
    public function update(EntityKey $entity, array $operations, ?string $actor = null): void
    {
        $record = $this->store->snapshot()->records[$entity->key()] ?? throw new \LogicException('Fixture entity is absent');
        $this->process($entity, MutationKind::Update, $operations, $record->version->value, $actor);
    }

    public function delete(EntityKey $entity, ?string $actor = null): void
    {
        $record = $this->store->snapshot()->records[$entity->key()] ?? throw new \LogicException('Fixture entity is absent');
        $this->process($entity, MutationKind::Delete, [], $record->version->value, $actor);
    }

    /** @param list<FieldOperation> $operations */
    private function process(EntityKey $entity, MutationKind $kind, array $operations, int $base, ?string $actor): void
    {
        $number = $this->nextMutation++;
        $mutation = new Mutation(
            'view-mutation-'.$number,
            $entity,
            new Replica('view-replica-'.$number),
            new MutationSequence(1),
            $kind,
            new RecordVersion($base),
            $operations,
        );
        ($this->engine ?? throw new \LogicException('Fixture engine is absent'))->process($mutation, new AdapterContext($actor, 'view-tests'));
    }
}
