---
title: "Threat model and limitations"
weight: 10
description: "Separate correctness guarantees from host security and durability."
---

# Threat model and limitations

This spike protects against accidental duplicate delivery, reordered mutation queues, conflicting offline edits, future base versions, mismatched identities and partial in-memory commits. It assumes one authoritative engine and correctly authenticated client context supplied by its host.

The core separates trusted actor/integration context from mutation payload but does not authenticate users, authorize entity types/fields, enforce tenant membership, encrypt data, rate-limit requests or verify that a submitted replica ID belongs to a caller. Space keys separate data and sequences; that is not authorization. Before exposing an API, the host must bind authenticated access to the allowed space, public types, fields and replica ownership. A base within the current range is accepted client context, not proof the client actually observed it. `from` never proves causality.

Field payloads accept JSON-compatible data only; nonfinite numbers, arbitrary PHP objects and depth beyond 64 are rejected. Values are whole-field replacements. There is no payload-byte quota, array-length quota or candidate limit yet. Journal/feed/conflict history grows indefinitely; do not expose unrestricted writes or assume bounded memory. Snapshots and transaction copies are deliberately simple and are unsuitable for large datasets.

In-memory state is lost on process exit. The store is synchronous and single-process, refuses nested/concurrent transactions, and offers no interprocess locking or crash durability. A fiber that suspends during a transaction holds that store busy until it resumes. SQL commit ordering, storage failure recovery, restore, compaction, schema migration, durable bootstrap sessions, wire serialization, HTTP and framework integration remain future work.

There are no production-readiness or external conformance claims. Report security concerns through an established private channel to the maintainer of the checkout; no security mailbox or hosted reporting service is provisioned by this package.

View identity, filter fingerprints, schema versions and epochs prevent accidental cursor-context reuse; they are not access tokens. The host authorizes every bootstrap/delta request and controls token serialization. View definitions and validators are trusted application code. On process restart or history reset, invalidate old sessions and rotate the epoch.
