<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

readonly class ValidationResult
{
    /** @var list<ValidationFailure> */
    public array $failures;

    /** @param list<ValidationFailure> $failures */
    public function __construct(array $failures = [])
    {
        $copy = [];
        foreach ($failures as $failure) {
            $copy[] = $failure;
        }
        $this->failures = $copy;
    }

    public function isValid(): bool
    {
        return $this->failures === [];
    }
}
