<?php

declare(strict_types=1);

namespace Cbox\Sync\Client;

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Replica;

class InMemoryOutboxStore implements OutboxStore
{
    /** @var list<Mutation> */
    private array $queue = [];

    /** @var list<array{mutation: Mutation, reason: string}> */
    private array $abandoned = [];

    /** @var array<string, int> */
    private array $acknowledged = [];

    /** @var array<string, true> */
    private array $attempted = [];

    /** @var array<string, EntityKey> handle key => the name the server gave it */
    private array $names = [];

    private bool $active = false;

    /** @var array<string, int> queue order, as the durable store's position */
    private array $positions = [];

    public function append(Mutation $mutation): void
    {
        foreach ($this->queue as $queued) {
            if ($queued->id === $mutation->id) {
                throw new InvalidRequest('Mutation identity is already queued: '.$mutation->id);
            }
        }
        // Abandoning does not free the identity. The durable store keeps the
        // row and its primary key, so letting it be reused here would be a
        // recovery flow that works in development and fails in production.
        foreach ($this->abandoned as $entry) {
            if ($entry['mutation']->id === $mutation->id) {
                throw new InvalidRequest('Mutation identity is already queued: '.$mutation->id);
            }
        }
        $this->positions[$mutation->id] = count($this->positions) + 1;
        $this->queue[] = $mutation;
    }

    /** @var array<string, array<string, array<string, string>>> type => handle => space => name */
    private array $handles = [];

    public function rekey(EntityKey $from, EntityKey $to, bool $creates = true): void
    {
        $this->names[$from->key()] = $to;
        $this->handles[$from->type][$from->id][$from->space] = $to->id;
        foreach ($this->queue as $index => $mutation) {
            // A create only when asked: after the server named one, another
            // create queued for the same handle is a record of its own.
            if ($mutation->entity->equals($from) && ($creates || $mutation->kind !== MutationKind::Create)) {
                $this->queue[$index] = $mutation->withEntity($to);
            }
        }
    }

    public function createFor(string $entityType, string $entityId, ?string $space = null): ?Mutation
    {
        foreach ($this->queue as $mutation) {
            if ($mutation->kind === MutationKind::Create && $mutation->entity->type === $entityType && $mutation->entity->id === $entityId
                && ($space === null || $mutation->entity->space === $space)) {
                return $mutation;
            }
        }

        return null;
    }

    public function handleSpaces(string $entityType, string $handle): array
    {
        $spaces = array_keys($this->handles[$entityType][$handle] ?? []);
        $creates = array_merge($this->queue, array_map(fn (array $entry): Mutation => $entry['mutation'], $this->abandoned));
        foreach ($creates as $mutation) {
            if ($mutation->kind === MutationKind::Create && $mutation->entity->type === $entityType && $mutation->entity->id === $handle) {
                $spaces[] = $mutation->entity->space;
            }
        }

        return array_values(array_unique(array_map('strval', $spaces)));
    }

    public function recordName(EntityKey $handle, string $name): void
    {
        $this->names[$handle->key()] = new EntityKey($handle->space, $handle->type, $name);
        $this->handles[$handle->type][$handle->id][$handle->space] = $name;
    }

    public function replace(Mutation $mutation): void
    {
        foreach ($this->queue as $index => $queued) {
            if ($queued->id === $mutation->id) {
                $this->queue[$index] = $mutation;
            }
        }
    }

    public function head(?string $entityType = null, ?string $space = null): ?Mutation
    {
        foreach ($this->queue as $mutation) {
            if (($entityType === null || $mutation->entity->type === $entityType) && ($space === null || $mutation->entity->space === $space)) {
                return $mutation;
            }
        }

        return null;
    }

    public function acknowledged(Replica $replica, string $space): int
    {
        return $this->acknowledged[self::stream($replica, $space)] ?? 0;
    }

    public function setAcknowledged(Replica $replica, string $space, int $sequence): void
    {
        $key = self::stream($replica, $space);
        $this->acknowledged[$key] = max($sequence, $this->acknowledged[$key] ?? 0);
    }

    public function resetAcknowledged(Replica $replica, string $space, int $sequence, int $expected): bool
    {
        $key = self::stream($replica, $space);
        if (($this->acknowledged[$key] ?? 0) !== $expected) {
            return false;
        }
        $this->acknowledged[$key] = $sequence;

        return true;
    }

    public function namedAs(string $entityType, string $handle): ?string
    {
        $names = array_unique($this->handles[$entityType][$handle] ?? []);

        return count($names) === 1 ? reset($names) : null;
    }

    public function firstFor(string $entityType, string $entityId): ?Mutation
    {
        foreach ($this->queue as $mutation) {
            if ($mutation->entity->type === $entityType && $mutation->entity->id === $entityId) {
                return $mutation;
            }
        }

        return null;
    }

    public function find(string $mutationId): ?Mutation
    {
        foreach ($this->queue as $mutation) {
            if ($mutation->id === $mutationId) {
                return $mutation;
            }
        }

        return null;
    }

    public function markSent(Mutation $numbered): void
    {
        $this->replace($numbered);
        $this->attempted[$numbered->id] = true;
    }

    public function unmarkSent(string $mutationId): void
    {
        unset($this->attempted[$mutationId]);
    }

    public function isSent(string $mutationId): bool
    {
        return isset($this->attempted[$mutationId]);
    }

    /** @var array<string, int> */
    private array $sends = [];

    public function countSend(string $mutationId): void
    {
        $this->sends[$mutationId] = ($this->sends[$mutationId] ?? 0) + 1;
    }

    public function countAnswer(string $mutationId): void
    {
        $this->sends[$mutationId] = max(0, ($this->sends[$mutationId] ?? 0) - 1);
    }

    public function clearSends(string $mutationId): void
    {
        unset($this->sends[$mutationId]);
    }

    public function unanswered(string $mutationId): int
    {
        return $this->sends[$mutationId] ?? 0;
    }

    public function queuedOn(Replica $replica, string $space): array
    {
        return array_values(array_filter($this->queue, fn (Mutation $m): bool => $m->replica->id === $replica->id && $m->entity->space === $space));
    }

    public function lockStream(Replica $replica, string $space): void {}

    public function inFlight(Replica $replica, string $space): ?Mutation
    {
        foreach ($this->queue as $mutation) {
            if (isset($this->attempted[$mutation->id]) && $mutation->replica->id === $replica->id && $mutation->entity->space === $space) {
                return $mutation;
            }
        }

        return null;
    }

    public function relabel(string $entityType, string $from, string $to): void
    {
        foreach ($this->queue as $index => $mutation) {
            if ($mutation->entity->type === $entityType && $mutation->entity->space === $from) {
                $this->queue[$index] = $mutation->withEntity(new EntityKey($to, $entityType, $mutation->entity->id));
            }
        }
    }

    public function nameOf(EntityKey $handle): ?EntityKey
    {
        return $this->names[$handle->key()] ?? null;
    }

    private static function stream(Replica $replica, string $space): string
    {
        return $space."\0".$replica->id;
    }

    public function acknowledge(string $mutationId): void
    {
        $this->queue = array_values(array_filter($this->queue, fn (Mutation $m): bool => $m->id !== $mutationId));
    }

    public function abandon(string $mutationId, string $reason): void
    {
        foreach ($this->queue as $mutation) {
            if ($mutation->id === $mutationId) {
                $this->abandoned[] = ['mutation' => $mutation, 'reason' => $reason];
            }
        }
        $this->acknowledge($mutationId);
    }

    public function abandoned(): array
    {
        $reported = array_values(array_filter($this->abandoned, fn (array $entry): bool => ! isset($this->dismissed[$entry['mutation']->id])));
        // In queue order, as the durable store lists them.
        usort($reported, fn (array $a, array $b): int => ($this->positions[$a['mutation']->id] ?? 0) <=> ($this->positions[$b['mutation']->id] ?? 0));

        return $reported;
    }

    public function setReason(string $mutationId, string $reason): void
    {
        foreach ($this->abandoned as $index => $entry) {
            if ($entry['mutation']->id === $mutationId) {
                $this->abandoned[$index]['reason'] = $reason;
            }
        }
    }

    /** @var array<string, true> */
    private array $dismissed = [];

    public function abandonedCreate(string $entityType, string $entityId, ?string $space = null): ?array
    {
        $found = null;
        $rank = null;
        foreach ($this->abandoned as $entry) {
            $mutation = $entry['mutation'];
            if ($mutation->kind !== MutationKind::Create || $mutation->entity->type !== $entityType || $mutation->entity->id !== $entityId
                || ($space !== null && $mutation->entity->space !== $space)) {
                continue;
            }
            // Reported before dismissed, then the one queued last - as the
            // durable store orders them.
            $candidate = [isset($this->dismissed[$mutation->id]) ? 0 : 1, $this->positions[$mutation->id] ?? 0];
            if ($rank === null || $candidate > $rank) {
                [$found, $rank] = [$entry, $candidate];
            }
        }

        return $found;
    }

    public function markDismissed(string $mutationId): void
    {
        $this->dismissed[$mutationId] = true;
    }

    public function forgetDismissedCreates(string $entityType, string $entityId): void
    {
        foreach ($this->abandoned as $entry) {
            $mutation = $entry['mutation'];
            if (isset($this->dismissed[$mutation->id]) && $mutation->kind === MutationKind::Create && $mutation->entity->type === $entityType && $mutation->entity->id === $entityId) {
                $this->forget($mutation->id);
            }
        }
    }

    public function forget(string $mutationId): void
    {
        unset($this->dismissed[$mutationId]);
        $this->acknowledge($mutationId);
        $this->abandoned = array_values(array_filter($this->abandoned, fn (array $entry): bool => $entry['mutation']->id !== $mutationId));
    }

    public function abandonedOne(string $mutationId): ?array
    {
        foreach ($this->abandoned() as $entry) {
            if ($entry['mutation']->id === $mutationId) {
                return $entry;
            }
        }

        return null;
    }

    public function dismiss(string $mutationId): void
    {
        $this->abandoned = array_values(array_filter(
            $this->abandoned,
            fn (array $entry): bool => $entry['mutation']->id !== $mutationId,
        ));
    }

    public function pending(?string $entityType = null): int
    {
        if ($entityType === null) {
            return count($this->queue);
        }

        return count(array_filter($this->queue, fn (Mutation $m): bool => $m->entity->type === $entityType));
    }

    public function queued(array $entityTypes): array
    {
        return array_values(array_filter($this->queue, fn (Mutation $m): bool => ! isset($this->attempted[$m->id]) && in_array($m->entity->type, $entityTypes, true)));
    }

    public function transaction(\Closure $callback): mixed
    {
        if ($this->active) {
            throw new TransientFailure('Nested outbox transaction is unsupported');
        }
        $this->active = true;
        $snapshot = [$this->queue, $this->abandoned, $this->acknowledged, $this->names, $this->attempted, $this->sends, $this->handles, $this->dismissed, $this->positions];
        try {
            return $callback();
        } catch (\Throwable $failure) {
            [$this->queue, $this->abandoned, $this->acknowledged, $this->names, $this->attempted, $this->sends, $this->handles, $this->dismissed, $this->positions] = $snapshot;

            throw $failure;
        } finally {
            $this->active = false;
        }
    }
}
