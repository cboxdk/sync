<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Client\Contracts\ClientState;
use Cbox\Sync\Client\InMemoryClientState;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\ValueObjects\EntityKey;

/**
 * Applies bootstrap pages and deltas across overlapping views.
 *
 * All knowledge lives in a ClientState, so a device that is killed mid-page
 * comes back knowing what it knew. Each page is applied inside one state
 * transaction: records, watermarks, memberships and the cursor move together,
 * because a cursor that advanced without its records would claim progress the
 * local data does not have.
 */
class MultiViewClient
{
    private ClientState $state;

    public function __construct(?ClientState $state = null)
    {
        $this->state = $state ?? new InMemoryClientState;
    }

    public function applyBootstrap(BootstrapPage $page): void
    {
        $contextKey = $page->context->fingerprint();
        if ($this->state->bootstrapTokenApplied($contextKey, $page->token->value)) {
            return;
        }
        if ($this->state->cursor($contextKey) !== null) {
            throw new InvalidRequest('Bootstrap page received after this view entered delta');
        }
        $expected = $this->state->nextBootstrapToken($contextKey);
        if (($expected === null && $page->offset !== 0) || ($expected !== null && $expected !== $page->token->value)) {
            throw new InvalidRequest('Bootstrap page is out of order');
        }

        $this->state->transaction(function () use ($page, $contextKey): void {
            $this->state->putContext($page->context);
            foreach ($page->records as $record) {
                $this->applyRecord($contextKey, $record);
            }
            $this->state->markBootstrapTokenApplied($contextKey, $page->token->value);
            if ($page->nextToken !== null) {
                $this->state->setNextBootstrapToken($contextKey, $page->nextToken->value);

                return;
            }
            $this->state->setNextBootstrapToken($contextKey, null);
            $this->state->putCursor($page->cursor ?? throw new InvalidRequest('Final bootstrap page has no cursor'));
        });
    }

    public function applyDelta(DeltaPage $page): void
    {
        $contextKey = $page->cursor->context->fingerprint();
        if ($page->previousCursor->context->fingerprint() !== $contextKey) {
            throw new InvalidRequest('Delta page context mismatch');
        }
        $current = $this->state->cursor($contextKey) ?? throw new InvalidRequest('Delta received before this view completed bootstrap');
        if ($page->cursor->position->value <= $current->position->value) {
            return;
        }
        if ($page->previousCursor->position->value !== $current->position->value) {
            throw new InvalidRequest('Delta page is out of order');
        }

        $this->state->transaction(function () use ($page, $contextKey): void {
            foreach ($page->commits as $commit) {
                if ($commit->sourceSequence->value <= $page->previousCursor->position->value || $commit->sourceSequence->value > $page->cursor->position->value) {
                    throw new InvalidRequest('Projected commit is outside the delta cursor range');
                }
                foreach ($commit->changes as $change) {
                    $this->applyChange($contextKey, $change);
                }
            }
            $this->state->putCursor($page->cursor);
        });
    }

    public function resetView(CursorContext $context): void
    {
        $contextKey = $context->fingerprint();
        $this->state->transaction(function () use ($contextKey): void {
            foreach ($this->state->members($contextKey) as $entity) {
                $this->state->removeMembership($entity, $contextKey);
                // Canonical knowledge - versions and tombstones - deliberately
                // survives: it is what stops a later page from resurrecting
                // something this client already saw deleted.
                if ($this->state->memberships($entity) === []) {
                    $this->state->forgetRecord($entity);
                }
            }
            $this->state->forgetView($contextKey);
        });
    }

    public function record(EntityKey $entity): ?EntityRecord
    {
        return $this->state->record($entity);
    }

    public function belongsTo(EntityKey $entity, string $viewId): bool
    {
        foreach ($this->state->memberships($entity) as $contextKey) {
            if ($this->state->context($contextKey)?->viewId === $viewId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The token this view is waiting for, when a bootstrap was interrupted
     * part-way.
     *
     * A caller that opened a fresh bootstrap instead would be handed a page
     * this client refuses as out of order, and no amount of retrying would
     * change that. Resuming is the only way back.
     */
    public function pendingBootstrapToken(CursorContext $context): ?BootstrapToken
    {
        $contextKey = $context->fingerprint();
        if ($this->state->cursor($contextKey) !== null) {
            return null;
        }
        $token = $this->state->nextBootstrapToken($contextKey);

        return $token === null ? null : new BootstrapToken($token);
    }

    /** The context this client recorded under a fingerprint, if it has seen it. */
    public function contextFor(string $fingerprint): ?CursorContext
    {
        return $this->state->context($fingerprint);
    }

    public function cursor(CursorContext $context): ?ViewCursor
    {
        return $this->state->cursor($context->fingerprint());
    }

    private function applyRecord(string $contextKey, EntityRecord $record): void
    {
        $entity = $record->entity;
        $version = $record->version->value;
        $tombstone = $this->state->tombstone($entity);
        if ($tombstone !== null && $version <= $tombstone) {
            return;
        }

        $this->state->addMembership($entity, $contextKey);
        if ($version < $this->state->version($entity)) {
            return;
        }

        $this->state->setVersion($entity, $version);
        if ($version >= ($this->state->record($entity)?->version->value ?? 0)) {
            $this->state->putRecord($record);
        }
    }

    private function applyChange(string $contextKey, ViewChange $change): void
    {
        $entity = $change->entity;
        $version = $change->recordVersion->value;
        if ($change->kind === ViewChangeKind::Upsert) {
            $this->applyRecord($contextKey, $change->record ?? throw new \LogicException('Upsert without record'));

            return;
        }

        $this->state->setVersion($entity, max($version, $this->state->version($entity)));
        if ($change->kind === ViewChangeKind::Deleted) {
            $this->state->setTombstone($entity, max($version, $this->state->tombstone($entity) ?? 0));
            $this->state->forgetRecord($entity);
            $this->state->forgetMemberships($entity);

            return;
        }

        $this->state->removeMembership($entity, $contextKey);
        if ($this->state->memberships($entity) === []) {
            $this->state->forgetRecord($entity);
        }
    }
}
