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

    public function head(): ?Mutation
    {
        return $this->queue[0] ?? null;
    }

    public function acknowledged(Replica $replica): int
    {
        return $this->acknowledged[$replica->id] ?? 0;
    }

    public function setAcknowledged(Replica $replica, int $sequence): void
    {
        $this->acknowledged[$replica->id] = max($sequence, $this->acknowledged[$replica->id] ?? 0);
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

    public function pending(): int
    {
        return count($this->queue);
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
