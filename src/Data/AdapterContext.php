<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\Exceptions\InvalidRequest;

/** Constructed by trusted host code, never deserialized from mutation payload. */
readonly class AdapterContext
{
    public function __construct(public ?string $actorId = null, public ?string $integrationId = null)
    {
        if ($actorId === '' || $integrationId === '') {
            throw new InvalidRequest('Trusted identities must be nonempty when present');
        }
    }
}
