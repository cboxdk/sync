---
title: "Testing and fakes"
weight: 20
description: "Exercise real sync behavior and inject a failed commit."
---

# Testing and fakes

Run the verification commands:

```sh
composer test
composer lint
composer analyse
composer license-check
composer security-audit
composer sbom
composer docs:requirements
composer simulate
```

CI runs PHP 8.4 and 8.5, checks generated-file drift, and executes the simulator. `composer qa` combines formatting checks, PHPStan max, Pest, licenses and a full locked dependency audit. Formatting changes can be applied with `composer format`.

`Testing\InteractsWithSync` provides `setUpSync()`, `seedRecord()`, `mutation()`, `write()`, `record()` and `openConflicts()` for test harnesses. The package's `tests/TestCase.php` uses this same trait. It supplies a deterministic ID fake and real in-memory storage. `tests/Fixtures/SyncHarness.php` makes the trait composition visible to PHPStan.

`Testing\FailingStore::failNextCommit()` throws `TransientFailure` after the transaction has staged all effects but before publication. The rollback test compares the full state before and after failure, including domain fields, conflict candidates, receipts, acknowledgements and commits. A retry then commits once. `Testing\FakeIdGenerator` provides repeatable IDs; failed transactions may consume an ID without consuming a commit or mutation sequence.

The suite covers independent edits, 100 clients in seeded orders, retries, same values, null/unset, malformed/future versions, gaps, changed identities, dependent writes, permanent rejection, transient failure, deletion, atomic versus partial updates, stale/partial resolution, late candidates, feed pages and PHP reference isolation.

The package has zero runtime dependencies. Composer returns an error for the empty `audit --no-dev` set, so the automated audit checks the entire lock file, including development tools.

Architecture tests additionally exercise atomic default and complete proposal recovery, explicit partial mode, strict revision failures even for equal targets, RejectOnConflict, trusted provenance, no-op metadata resolution, merged-state validation and staged rollback. View tests exercise contextual cursor reuse/reset, before/after membership, multi-view ownership, and writes during snapshot pagination followed by delta convergence.
