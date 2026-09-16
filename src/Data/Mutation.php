<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

readonly class Mutation
{
    /** @var list<FieldOperation> */
    public array $operations;

    /**
     * @param  list<FieldOperation>  $operations
     */
    public function __construct(
        public string $id,
        public EntityKey $entity,
        public Replica $replica,
        public MutationSequence $sequence,
        public MutationKind $kind,
        public RecordVersion $baseVersion,
        array $operations = [],
        public bool $atomic = true,
        public ?string $dependsOn = null,
        public ?Resolution $resolution = null,
        public ?RecordVersion $expectedVersion = null,
    ) {
        $operationsCopy = [];
        foreach ($operations as $value) {
            $operationsCopy[] = $value;
        }
        $this->operations = $operationsCopy;

        if ($id === '' || $dependsOn === '' || $dependsOn === $id) {
            throw new InvalidRequest('Invalid mutation identity/dependency');
        }
        self::validateOperations($operations);
        if (($kind === MutationKind::Resolve) !== ($resolution !== null) || ($kind === MutationKind::Resolve && count($operations) !== 1)) {
            throw new InvalidRequest('Resolve requires exactly one field operation and resolution');
        }
        if ($kind === MutationKind::Delete && $operations !== []) {
            throw new InvalidRequest('Delete cannot carry field operations');
        }
        if ($kind === MutationKind::Create && $baseVersion->value !== 0) {
            throw new InvalidRequest('Create base must be zero');
        }
    }

    /** @param array<array-key, mixed> $operations */
    private static function validateOperations(array $operations): void
    {
        if (! array_is_list($operations)) {
            throw new InvalidRequest('Operations must be a list');
        }
        $seen = [];
        foreach ($operations as $operation) {
            if (! $operation instanceof FieldOperation || isset($seen[$operation->field])) {
                throw new InvalidRequest('One operation per field is required');
            }
            $seen[$operation->field] = true;
        }
    }

    public function fingerprint(): string
    {
        // Compare payload content, never PHP's object-reference graph.
        $operations = [];
        foreach ($this->operations as $operation) {
            $operations[] = [
                $operation->field,
                $operation->value->exists,
                $operation->value->toJson(),
                $operation->from === null ? null : [$operation->from->exists, $operation->from->toJson()],
            ];
        }

        return hash('sha256', serialize([
            $this->id,
            [$this->entity->space, $this->entity->type, $this->entity->id],
            $this->replica->id,
            $this->sequence->value,
            $this->kind->value,
            $this->baseVersion->value,
            $operations,
            $this->atomic,
            $this->dependsOn,
            $this->expectedVersion?->value,
            $this->resolution === null ? null : [
                $this->resolution->groupId,
                $this->resolution->groupRevision,
                $this->resolution->candidateIds,
            ],
        ]));
    }
}
