<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\ValueObjects\EntityKey;

/** In-memory client reference with atomic page/cursor application across overlapping views. */
class MultiViewClient
{
    /** @var array<string, EntityRecord> */
    private array $records = [];

    /** @var array<string, int> Highest canonical revision observed, retained after deletion. */
    private array $versions = [];

    /** @var array<string, int> */
    private array $tombstones = [];

    /** @var array<string, array<string, true>> Entity key to context fingerprints. */
    private array $memberships = [];

    /** @var array<string, CursorContext> */
    private array $contexts = [];

    /** @var array<string, ViewCursor> */
    private array $cursors = [];

    /** @var array<string, string> Context fingerprint to next expected token. */
    private array $nextBootstrapTokens = [];

    /** @var array<string, array<string, true>> */
    private array $appliedBootstrapTokens = [];

    public function applyBootstrap(BootstrapPage $page): void
    {
        $contextKey = $page->context->fingerprint();
        if (isset($this->appliedBootstrapTokens[$contextKey][$page->token->value])) {
            return;
        }
        if (isset($this->cursors[$contextKey])) {
            throw new InvalidRequest('Bootstrap page received after this view entered delta');
        }
        $expected = $this->nextBootstrapTokens[$contextKey] ?? null;
        if (($expected === null && $page->offset !== 0) || ($expected !== null && $expected !== $page->token->value)) {
            throw new InvalidRequest('Bootstrap page is out of order');
        }

        $working = clone $this;
        $working->contexts[$contextKey] = $page->context;
        foreach ($page->records as $record) {
            $working->applyRecord($contextKey, $record);
        }
        $working->appliedBootstrapTokens[$contextKey][$page->token->value] = true;
        if ($page->nextToken !== null) {
            $working->nextBootstrapTokens[$contextKey] = $page->nextToken->value;
        } else {
            unset($working->nextBootstrapTokens[$contextKey]);
            $working->cursors[$contextKey] = $page->cursor ?? throw new InvalidRequest('Final bootstrap page has no cursor');
        }
        $this->publish($working);
    }

    public function applyDelta(DeltaPage $page): void
    {
        $contextKey = $page->cursor->context->fingerprint();
        if ($page->previousCursor->context->fingerprint() !== $contextKey) {
            throw new InvalidRequest('Delta page context mismatch');
        }
        $current = $this->cursors[$contextKey] ?? throw new InvalidRequest('Delta received before this view completed bootstrap');
        if ($page->cursor->position->value <= $current->position->value) {
            return;
        }
        if ($page->previousCursor->position->value !== $current->position->value) {
            throw new InvalidRequest('Delta page is out of order');
        }

        $working = clone $this;
        foreach ($page->commits as $commit) {
            if ($commit->sourceSequence->value <= $page->previousCursor->position->value || $commit->sourceSequence->value > $page->cursor->position->value) {
                throw new InvalidRequest('Projected commit is outside the delta cursor range');
            }
            foreach ($commit->changes as $change) {
                $working->applyChange($contextKey, $change);
            }
        }
        $working->cursors[$contextKey] = $page->cursor;
        $this->publish($working);
    }

    public function resetView(CursorContext $context): void
    {
        $contextKey = $context->fingerprint();
        foreach (array_keys($this->memberships) as $entityKey) {
            unset($this->memberships[$entityKey][$contextKey]);
            if ($this->memberships[$entityKey] === []) {
                unset($this->memberships[$entityKey], $this->records[$entityKey]);
            }
        }
        unset($this->contexts[$contextKey], $this->cursors[$contextKey], $this->nextBootstrapTokens[$contextKey], $this->appliedBootstrapTokens[$contextKey]);
    }

    public function record(EntityKey $entity): ?EntityRecord
    {
        return $this->records[$entity->key()] ?? null;
    }

    public function belongsTo(EntityKey $entity, string $viewId): bool
    {
        foreach (array_keys($this->memberships[$entity->key()] ?? []) as $contextKey) {
            if (($this->contexts[$contextKey] ?? null)?->viewId === $viewId) {
                return true;
            }
        }

        return false;
    }

    public function cursor(CursorContext $context): ?ViewCursor
    {
        return $this->cursors[$context->fingerprint()] ?? null;
    }

    private function applyRecord(string $contextKey, EntityRecord $record): void
    {
        $entityKey = $record->entity->key();
        $version = $record->version->value;
        if ($version <= ($this->tombstones[$entityKey] ?? -1)) {
            return;
        }

        $this->memberships[$entityKey][$contextKey] = true;
        if ($version < ($this->versions[$entityKey] ?? 0)) {
            return;
        }

        $this->versions[$entityKey] = $version;
        if ($version >= ($this->records[$entityKey]->version->value ?? 0)) {
            $this->records[$entityKey] = $record;
        }
    }

    private function applyChange(string $contextKey, ViewChange $change): void
    {
        $entityKey = $change->entity->key();
        $version = $change->recordVersion->value;
        if ($change->kind === ViewChangeKind::Upsert) {
            $record = $change->record ?? throw new \LogicException('Upsert without record');
            $this->applyRecord($contextKey, $record);

            return;
        }

        $this->versions[$entityKey] = max($version, $this->versions[$entityKey] ?? 0);
        if ($change->kind === ViewChangeKind::Deleted) {
            $this->tombstones[$entityKey] = max($version, $this->tombstones[$entityKey] ?? 0);
            unset($this->records[$entityKey], $this->memberships[$entityKey]);

            return;
        }

        unset($this->memberships[$entityKey][$contextKey]);
        if (($this->memberships[$entityKey] ?? []) === []) {
            unset($this->memberships[$entityKey], $this->records[$entityKey]);
        }
    }

    private function publish(self $working): void
    {
        $this->records = $working->records;
        $this->versions = $working->versions;
        $this->tombstones = $working->tombstones;
        $this->memberships = $working->memberships;
        $this->contexts = $working->contexts;
        $this->cursors = $working->cursors;
        $this->nextBootstrapTokens = $working->nextBootstrapTokens;
        $this->appliedBootstrapTokens = $working->appliedBootstrapTokens;
    }
}
