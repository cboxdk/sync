---
title: "Validate merged state"
weight: 50
description: "Check the resulting entity before committing domain changes."
---

# Validate merged state

Inject `Contracts\EntityValidator` as `Engine`'s `validator` argument. The default `Validation\AcceptAll` imposes no application domain rules. The core first checks preconditions, detects field conflicts and chooses resolver outcomes. It then calls `validate(ValidationContext): ValidationResult` with previous state, complete proposed state, mutation and trusted provenance, inside the store transaction.

```php
use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Data\{ValidationContext, ValidationFailure, ValidationResult};

$validator = new class implements EntityValidator {
    public function validate(ValidationContext $context): ValidationResult
    {
        if ($context->proposed->deleted) {
            return new ValidationResult;
        }
        return $context->proposed->value('title')->value() === $context->proposed->value('body')->value()
            ? new ValidationResult([
                new ValidationFailure('distinct_fields', 'Title and body must differ.', 'body'),
            ])
            : new ValidationResult;
    }
};

$engine = new \Cbox\Sync\Engine($store, validator: $validator);
```

A nonempty failure list returns `MutationStatus::ValidationFailed`. `result->validation->failures` contains typed code/message/optional-field details. The canonical entity and tentative conflict changes are discarded; the full attempted mutation, failure result and acknowledgement commit together. The result reports the unchanged canonical record version. To correct the proposal, use a new mutation ID and next sequence. Exact retry returns the original validation result rather than re-running checks.

Validation covers create, update, delete and resolution, including partial application and accepted no-op results. Pure unresolved atomic conflicts and precondition/resolver rejections have no proposed domain application and skip validation. With partial apply, the validator sees the complete entity with only the chosen partial changes applied; a failed rule rejects that partial result too. Same-value resolution is validated before candidate closure becomes visible.

A thrown exception escapes and rolls back **all** staged effects, including result and acknowledgement. An adapter can throw `TransientFailure` for a temporary database-check failure and retry the identical mutation. Deterministic domain failures should be returned as typed `ValidationFailure` values.

Database-dependent validators may use the same connection and active transaction as the storage adapter. `InMemoryStore::beforeCommit()` is also inside the publication boundary; an exception there rolls everything back. A validation callback alone cannot prevent races across entities. Uniqueness, capacity and reservations require suitable locks, constraints or transaction isolation in the future adapter. The in-memory reference offers no cross-process guarantee. Validators must avoid nontransactional external side effects.
