<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\Enums\ConflictDecision;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\FieldVersion;
use Cbox\Sync\ValueObjects\RecordVersion;

readonly class MutationResult
{
    /** @var array<string, ConflictDecision> */
    public array $decisions;

    /** @var array<string, FieldVersion> */
    public array $acceptedVersions;

    /** @var array<string, FieldConflict> */
    public array $conflicts;

    /** @var list<string> */
    public array $conflictGroupIds;

    /**
     * @param  array<string, ConflictDecision>  $decisions
     * @param  array<string, FieldVersion>  $acceptedVersions
     * @param  list<string>  $conflictGroupIds
     * @param  array<string, FieldConflict>  $conflicts
     */
    public function __construct(public MutationStatus $status, public RecordVersion $recordVersion = new RecordVersion, public ?CommitSequence $commitSequence = null, public ?string $reason = null, array $decisions = [], array $acceptedVersions = [], array $conflictGroupIds = [], public int $acknowledgedSequence = 0, public ?PreconditionFailure $preconditionFailure = null, public ?ValidationResult $validation = null, array $conflicts = [])
    {
        $conflictsCopy = [];
        foreach ($conflicts as $key => $conflict) {
            $conflictsCopy[$key] = $conflict;
        }
        $this->conflicts = $conflictsCopy;
        $decisionsCopy = [];
        foreach ($decisions as $key => $value) {
            $decisionsCopy[$key] = $value;
        }
        $this->decisions = $decisionsCopy;
        $acceptedVersionsCopy = [];
        foreach ($acceptedVersions as $key => $value) {
            $acceptedVersionsCopy[$key] = $value;
        }
        $this->acceptedVersions = $acceptedVersionsCopy;
        $conflictGroupIdsCopy = [];
        foreach ($conflictGroupIds as $value) {
            $conflictGroupIdsCopy[] = $value;
        }
        $this->conflictGroupIds = $conflictGroupIdsCopy;
    }
}
