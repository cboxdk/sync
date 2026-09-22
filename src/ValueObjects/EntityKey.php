<?php

declare(strict_types=1);

namespace Cbox\Sync\ValueObjects;

use Cbox\Sync\Exceptions\InvalidRequest;

readonly class EntityKey
{
    public function __construct(public string $space, public string $type, public string $id)
    {
        if ($space === '' || $type === '' || $id === '') {
            throw new InvalidRequest('Entity identity must not be empty');
        }
        // Bounded where a write is made (Mutation), not here: a key is also
        // rebuilt from storage and from continuation tokens, and a record an
        // earlier release stored with a longer id must stay readable.
    }

    public function key(): string
    {
        return serialize([$this->space, $this->type, $this->id]);
    }

    public function equals(self $other): bool
    {
        return $this->space === $other->space
            && $this->type === $other->type
            && $this->id === $other->id;
    }
}
