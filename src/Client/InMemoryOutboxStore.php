<?php

declare(strict_types=1);

namespace Cbox\Sync\Client;

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Data\Mutation;
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

    private bool $active = false;

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
        $this->queue[] = $mutation;
    }

    public function rekey(EntityKey $from, EntityKey $to): void
    {
        foreach ($this->queue as $index => $mutation) {
            if ($mutation->entity->equals($from)) {
                $this->queue[$index] = $mutation->withEntity($to);
            }
        }
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
        $this->acknowledged[$key] = $sequence;
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
        return $this->abandoned;
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

    public function transaction(\Closure $callback): mixed
    {
        if ($this->active) {
            throw new TransientFailure('Nested outbox transaction is unsupported');
        }
        $this->active = true;
        $snapshot = [$this->queue, $this->abandoned, $this->acknowledged];
        try {
            return $callback();
        } catch (\Throwable $failure) {
            [$this->queue, $this->abandoned, $this->acknowledged] = $snapshot;

            throw $failure;
        } finally {
            $this->active = false;
        }
    }
}
