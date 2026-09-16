<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\Exceptions\InvalidRequest;

readonly class ValidationFailure
{
    public function __construct(public string $code, public string $message, public ?string $field = null)
    {
        if ($code === '' || $message === '' || $field === '') {
            throw new InvalidRequest('Validation failures require a code and message');
        }
    }
}
