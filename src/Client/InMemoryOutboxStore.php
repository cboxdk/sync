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
    private array $assigned = [];

    private bool $active = false;

    public function append(Mutation $mutation): void
    {
        $this->queue[] = $mutation;
    }

    public function head(): ?Mutation
    {
        return $this->queue[0] ?? null;
    }

    public function nextSequence(Replica $replica): int
    {
        return $this->assigned[$replica->id] = ($this->assigned[$replica->id] ?? 0) + 1;
    }

    public function acknowledge(string $mutationId): void
    {
        $this->queue = array_values(array_filter($this->queue, fn (Mutation $m): bool => $m->id !== $mutationId));
    }

    public function acknowledgeThrough(Replica $replica, int $sequence): void
    {
        $this->queue = array_values(array_filter(
            $this->queue,
            fn (Mutation $m): bool => $m->replica->id !== $replica->id || $m->sequence->value > $sequence,
        ));
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
        $snapshot = [$this->queue, $this->abandoned, $this->assigned];
        try {
            return $callback();
        } catch (\Throwable $failure) {
            [$this->queue, $this->abandoned, $this->assigned] = $snapshot;

            throw $failure;
        } finally {
            $this->active = false;
        }
    }
}
