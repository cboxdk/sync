<?php

declare(strict_types=1);

namespace Cbox\Sync\Validation;

use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Data\ValidationResult;

/** No application domain constraints are implied by the framework-independent core. */
class AcceptAll implements EntityValidator
{
    public function validate(ValidationContext $context): ValidationResult
    {
        return new ValidationResult;
    }
}
