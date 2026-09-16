<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\Change;
use Cbox\Sync\Data\Commit;
use Cbox\Sync\Enums\ChangeKind;
use Cbox\Sync\Exceptions\HistoryUnavailable;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\ValueObjects\CommitSequence;

/** Projects the space log into one view: a paginated bootstrap, then contextual deltas. */
class ViewSyncService
{
    private BootstrapSessions $sessions;

    public function __construct(private Store $store, public readonly string $schemaVersion, public readonly string $epoch, ?BootstrapSessions $sessions = null)
    {
        if ($schemaVersion === '' || $epoch === '') {
            throw new InvalidRequest('Schema version and epoch must not be empty');
        }
        $this->sessions = $sessions ?? new FrozenBootstrapSessions($store);
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

        return $this->sessions->open($context, $view, $this->store->watermark($context->space), $pageSize);
    }

    /**
     * Serve one page of an open bootstrap.
     *
     * The caller must pass the context it expects, the same way delta() carries
     * one in its cursor. A token names the space it will read, so serving it on
     * the token alone makes it a bearer capability: any holder reads that
     * tenant, and an epoch rotation - the only tool for forcing every client to
     * reset - cannot revoke it. Comparing the page's context against a
     * caller-derived one closes both, because space, schema version and epoch
     * are all inside the fingerprint.
     */
    public function bootstrap(CursorContext $context, ViewDefinition $view, BootstrapToken $token): BootstrapPage
    {
        $this->validateContext($context, $view);
        $page = $this->sessions->page($token, $view);
        if (! hash_equals($context->fingerprint(), $page->context->fingerprint())) {
            throw new ResetRequired(ResetReason::ContextChanged);
        }

        return $page;
    }

    public function delta(ViewCursor $cursor, ViewDefinition $view, int $commitBudget = 100): DeltaPage
    {
        $this->validateContext($cursor->context, $view);
        if ($commitBudget < 1) {
            throw new InvalidRequest('Delta commit budget must be at least one');
        }

        $space = $cursor->context->space;
        if ($cursor->position->value > $this->store->watermark($space)->value) {
            throw new ResetRequired(ResetReason::CursorAhead);
        }

        try {
            // One extra commit answers hasMore without a second query.
            $available = $this->store->commitsAfter($space, $cursor->position->value, $commitBudget + 1);
        } catch (HistoryUnavailable) {
            throw new ResetRequired(ResetReason::HistoryPruned);
        }
        $hasMore = count($available) > $commitBudget;
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
            $hasMore,
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
}
