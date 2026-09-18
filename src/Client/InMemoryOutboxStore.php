<?php

declare(strict_types=1);

namespace Cbox\Sync\Client;

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Exceptions\TransientFailure;
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
        $this->queue[] = $mutation;
    }

    public function head(?string $entityType = null): ?Mutation
    {
        foreach ($this->queue as $mutation) {
            if ($entityType === null || $mutation->entity->type === $entityType) {
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
