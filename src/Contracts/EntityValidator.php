<?php

declare(strict_types=1);

namespace Cbox\Sync\Contracts;

use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Data\ValidationResult;

interface EntityValidator
{
    public function validate(ValidationContext $context): ValidationResult;
}
