<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

readonly class Receipt
{
    public function __construct(public Mutation $mutation, public MutationResult $result, public Provenance $provenance) {}
}
