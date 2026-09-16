<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\Change;
use Cbox\Sync\Data\Commit;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Enums\ChangeKind;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\ValueObjects\CommitSequence;

/** In-memory reference implementation of frozen bootstrap and contextual delta. */
class ViewSyncService
{
    /** @var array<string, BootstrapSession> */
    private array $sessions = [];

    /** @var array<string, BootstrapRequest> */
    private array $tokens = [];

    public function __construct(private Store $store, public readonly string $schemaVersion, public readonly string $epoch)
    {
        if ($schemaVersion === '' || $epoch === '') {
            throw new InvalidRequest('Schema version and epoch must not be empty');
        }
    }

    public function context(string $space, ViewDefinition $view): CursorContext
    {
        return CursorContext::forView($space, $view, $this->schemaVersion, $this->epoch);
    }

    public function openBootstrap(CursorContext $context, ViewDefinition $view, int $pageSize = 100): BootstrapToken
    {
        $this->validateContext($context, $view);
        if ($pageSize < 1) {
            throw new InvalidRequest('Bootstrap page size must be at least one');
        }

        $state = $this->store->snapshot();
        $records = [];
        foreach ($state->records as $record) {
            if ($record->entity->space === $context->space && ! $record->deleted && $view->includes($record)) {
                $records[] = $record;
            }
        }
        usort($records, fn (EntityRecord $left, EntityRecord $right): int => [$left->entity->type, $left->entity->id] <=> [$right->entity->type, $right->entity->id]);

        $commits = $state->commits[$context->space] ?? [];
        $last = $commits === [] ? 0 : $commits[array_key_last($commits)]->sequence->value;
        $sessionId = bin2hex(random_bytes(16));
        $this->sessions[$sessionId] = new BootstrapSession($context, $records, new CommitSequence($last), $pageSize);

        return $this->token($sessionId, 0);
    }

    public function bootstrap(BootstrapToken $token): BootstrapPage
    {
        $request = $this->tokens[$token->value] ?? throw new ResetRequired(ResetReason::BootstrapTokenUnknown);
        $session = $this->sessions[$request->sessionId] ?? throw new ResetRequired(ResetReason::BootstrapTokenUnknown);
        $records = array_slice($session->records, $request->offset, $session->pageSize);
        $nextOffset = $request->offset + count($records);
        if ($nextOffset < count($session->records)) {
            return new BootstrapPage($records, $this->token($request->sessionId, $nextOffset), null, $session->context, $token, $request->offset);
        }

        return new BootstrapPage($records, null, new ViewCursor($session->context, $session->watermark), $session->context, $token, $request->offset);
    }

    public function delta(ViewCursor $cursor, ViewDefinition $view, int $commitBudget = 100): DeltaPage
    {
        $this->validateContext($cursor->context, $view);
        if ($commitBudget < 1) {
            throw new InvalidRequest('Delta commit budget must be at least one');
        }

        $state = $this->store->snapshot();
        $source = $state->commits[$cursor->context->space] ?? [];
        $last = $source === [] ? 0 : $source[array_key_last($source)]->sequence->value;
        if ($cursor->position->value > $last) {
            throw new ResetRequired(ResetReason::CursorAhead);
        }

        $available = [];
        foreach ($source as $commit) {
            if ($commit->sequence->value > $cursor->position->value) {
                $available[] = $commit;
            }
        }
        $consumed = array_slice($available, 0, $commitBudget);
        $projected = [];
        $position = $cursor->position->value;
        foreach ($consumed as $commit) {
            $changes = $this->project($commit, $view);
            if ($changes !== []) {
                $projected[] = new ViewCommit($commit->sequence, $changes);
            }
            $position = $commit->sequence->value;
        }

        return new DeltaPage(
            $projected,
            $cursor,
            new ViewCursor($cursor->context, new CommitSequence($position)),
            count($available) > count($consumed),
        );
    }

    /** @return list<ViewChange> */
    private function project(Commit $commit, ViewDefinition $view): array
    {
        $projected = [];
        foreach ($commit->changes as $change) {
            $item = $this->projectChange($change, $view, count($projected));
            if ($item !== null) {
                $projected[] = $item;
            }
        }

        return $projected;
    }

    private function projectChange(Change $change, ViewDefinition $view, int $ordinal): ?ViewChange
    {
        if ($change->kind !== ChangeKind::Record && $change->kind !== ChangeKind::Deleted) {
            return null;
        }

        $before = $change->previousRecord;
        $after = $change->record;
        $beforeIncluded = $before !== null && ! $before->deleted && $view->includes($before);
        $afterIncluded = $after !== null && ! $after->deleted && $view->includes($after);
        $deleted = $change->kind === ChangeKind::Deleted || ($after !== null && $after->deleted);
        $entity = $after !== null ? $after->entity : $before?->entity;
        if ($entity === null) {
            throw new \LogicException('Canonical change has neither a previous nor a new record');
        }

        if ($deleted) {
            return $beforeIncluded
                ? new ViewChange($ordinal, ViewChangeKind::Deleted, $entity, ($after ?? $before)->version, provenance: $change->provenance)
                : null;
        }
        if ($afterIncluded) {
            return new ViewChange($ordinal, ViewChangeKind::Upsert, $entity, $after->version, $after, $change->provenance);
        }
        if ($beforeIncluded) {
            return new ViewChange($ordinal, ViewChangeKind::RemovedFromScope, $entity, ($after ?? $before)->version, provenance: $change->provenance);
        }

        return null;
    }

    private function validateContext(CursorContext $context, ViewDefinition $view): void
    {
        if ($context->schemaVersion !== $this->schemaVersion
            || $context->epoch !== $this->epoch
            || $context->viewId !== $view->id()
            || $context->filterVersion !== $view->filterVersion()
            || $context->filterSignature !== $view->filterSignature()) {
            throw new ResetRequired(ResetReason::ContextChanged);
        }
    }

    private function token(string $sessionId, int $offset): BootstrapToken
    {
        $value = hash('sha256', serialize([$sessionId, $offset]));
        $this->tokens[$value] = new BootstrapRequest($sessionId, $offset);

        return new BootstrapToken($value);
    }
}
