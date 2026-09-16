<?php

declare(strict_types=1);

namespace Cbox\Sync\Client;

use Cbox\Sync\Client\Contracts\ClientState;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\Views\CursorContext;
use Cbox\Sync\Views\ViewCursor;

/** Reference client state. Fast, inspectable, and gone at process exit. */
class InMemoryClientState implements ClientState
{
    /** @var array<string, EntityRecord> */
    private array $records = [];

    /** @var array<string, int> */
    private array $versions = [];

    /** @var array<string, int> */
    private array $tombstones = [];

    /** @var array<string, array{entity: EntityKey, owners: array<string, true>}> */
    private array $memberships = [];

    /** @var array<string, CursorContext> */
    private array $contexts = [];

    /** @var array<string, ViewCursor> */
    private array $cursors = [];

    /** @var array<string, string> */
    private array $nextBootstrapTokens = [];

    /** @var array<string, array<string, true>> */
    private array $appliedBootstrapTokens = [];

    private bool $active = false;

    public function record(EntityKey $entity): ?EntityRecord
    {
        return $this->records[$entity->key()] ?? null;
    }

    public function putRecord(EntityRecord $record): void
    {
        $this->records[$record->entity->key()] = $record;
    }

    public function forgetRecord(EntityKey $entity): void
    {
        unset($this->records[$entity->key()]);
    }

    public function version(EntityKey $entity): int
    {
        return $this->versions[$entity->key()] ?? 0;
    }

    public function setVersion(EntityKey $entity, int $version): void
    {
        $this->versions[$entity->key()] = $version;
    }

    public function tombstone(EntityKey $entity): ?int
    {
        return $this->tombstones[$entity->key()] ?? null;
    }

    public function setTombstone(EntityKey $entity, int $version): void
    {
        $this->tombstones[$entity->key()] = $version;
    }

    public function memberships(EntityKey $entity): array
    {
        return array_keys($this->memberships[$entity->key()]['owners'] ?? []);
    }

    public function addMembership(EntityKey $entity, string $contextKey): void
    {
        $key = $entity->key();
        $this->memberships[$key] ??= ['entity' => $entity, 'owners' => []];
        $this->memberships[$key]['owners'][$contextKey] = true;
    }

    public function removeMembership(EntityKey $entity, string $contextKey): void
    {
        $key = $entity->key();
        unset($this->memberships[$key]['owners'][$contextKey]);
        if (($this->memberships[$key]['owners'] ?? []) === []) {
            unset($this->memberships[$key]);
        }
    }

    public function forgetMemberships(EntityKey $entity): void
    {
        unset($this->memberships[$entity->key()]);
    }

    public function members(string $contextKey): array
    {
        $entities = [];
        foreach ($this->memberships as $membership) {
            if (isset($membership['owners'][$contextKey])) {
                $entities[] = $membership['entity'];
            }
        }

        return $entities;
    }

    public function context(string $contextKey): ?CursorContext
    {
        return $this->contexts[$contextKey] ?? null;
    }

    public function putContext(CursorContext $context): void
    {
        $this->contexts[$context->fingerprint()] = $context;
    }

    public function cursor(string $contextKey): ?ViewCursor
    {
        return $this->cursors[$contextKey] ?? null;
    }

    public function putCursor(ViewCursor $cursor): void
    {
        $this->cursors[$cursor->context->fingerprint()] = $cursor;
    }

    public function nextBootstrapToken(string $contextKey): ?string
    {
        return $this->nextBootstrapTokens[$contextKey] ?? null;
    }

    public function setNextBootstrapToken(string $contextKey, ?string $token): void
    {
        if ($token === null) {
            unset($this->nextBootstrapTokens[$contextKey]);

            return;
        }
        $this->nextBootstrapTokens[$contextKey] = $token;
    }

    public function bootstrapTokenApplied(string $contextKey, string $token): bool
    {
        return isset($this->appliedBootstrapTokens[$contextKey][$token]);
    }

    public function markBootstrapTokenApplied(string $contextKey, string $token): void
    {
        $this->appliedBootstrapTokens[$contextKey][$token] = true;
    }

    public function forgetView(string $contextKey): void
    {
        unset($this->contexts[$contextKey], $this->cursors[$contextKey], $this->nextBootstrapTokens[$contextKey], $this->appliedBootstrapTokens[$contextKey]);
    }

    public function transaction(\Closure $callback): mixed
    {
        if ($this->active) {
            throw new TransientFailure('Nested client state transaction is unsupported');
        }
        $this->active = true;
        $snapshot = [$this->records, $this->versions, $this->tombstones, $this->memberships, $this->contexts, $this->cursors, $this->nextBootstrapTokens, $this->appliedBootstrapTokens];
        try {
            return $callback();
        } catch (\Throwable $failure) {
            [$this->records, $this->versions, $this->tombstones, $this->memberships, $this->contexts, $this->cursors, $this->nextBootstrapTokens, $this->appliedBootstrapTokens] = $snapshot;

            throw $failure;
        } finally {
            $this->active = false;
        }
    }
}
