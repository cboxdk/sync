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
