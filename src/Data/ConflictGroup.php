<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\ValueObjects\EntityKey;

readonly class ConflictGroup
{
    /** @var array<string, Candidate> */
    public array $candidates;

    /** @var array<string, string> */
    public array $resolved;

    /**
     * @param  array<string, Candidate>  $candidates
     * @param  array<string, string>  $resolved  Candidate ID => resolution mutation ID
     */
    public function __construct(public string $id, public EntityKey $entity, public string $field, public int $revision = 1, array $candidates = [], array $resolved = [])
    {
        $candidatesCopy = [];
        foreach ($candidates as $key => $value) {
            $candidatesCopy[$key] = $value;
        }
        $this->candidates = $candidatesCopy;
        $resolvedCopy = [];
        foreach ($resolved as $key => $value) {
            $resolvedCopy[$key] = $value;
        }
        $this->resolved = $resolvedCopy;
    }

    public function isOpen(): bool
    {
        return count($this->candidates) > count($this->resolved);
    }

    public function add(Candidate $candidate): self
    {
        if (isset($this->candidates[$candidate->id])) {
            return $this;
        }

        return new self($this->id, $this->entity, $this->field, $this->revision + 1, [...$this->candidates, $candidate->id => $candidate], $this->resolved);
    }

    /**
     * @param  list<string>  $ids
     */
    public function resolve(array $ids, string $mutationId): self
    {
        $resolved = $this->resolved;
        foreach ($ids as $id) {
            $resolved[$id] = $mutationId;
        }

        return new self($this->id, $this->entity, $this->field, $this->revision + 1, $this->candidates, $resolved);
    }
}
