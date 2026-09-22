---
title: "Threat model and limitations"
weight: 10
description: "Separate correctness guarantees from host security and durability."
---

# Threat model and limitations

The engine protects against accidental duplicate delivery, reordered mutation queues, conflicting offline edits, future base versions, mismatched identities and partial commits. It assumes one authoritative engine and correctly authenticated client context supplied by its host.

The core separates trusted actor/integration context from mutation payload but does not authenticate users, authorize entity types/fields, enforce tenant membership, encrypt data, rate-limit requests or verify that a submitted replica ID belongs to a caller. Space keys separate data and sequences; that is not authorization. Before exposing an API, the host must bind authenticated access to the allowed space, public types, fields and replica ownership. A base within the current range is accepted client context, not proof the client actually observed it. `from` never proves causality.

Field payloads accept JSON-compatible data only; nonfinite numbers, arbitrary PHP objects and depth beyond 64 are rejected. Values are whole-field replacements. There is no payload-byte quota, array-length quota or candidate limit yet. History grows until `Store::prune()` drops what is past the retention horizon; do not expose unrestricted writes or assume bounded memory. Snapshots and transaction copies are deliberately simple and are unsuitable for large datasets.

The in-memory store is lost on process exit and refuses nested or concurrent transactions; a fiber that suspends inside a transaction holds it busy until it resumes. The durable adapter serializes writers per space and is exercised against SQLite, MySQL and PostgreSQL. `migrate()` brings an older schema up to date; wire serialization, HTTP and framework integration live in the host (cboxdk/laravel-sync is one).

There are no production-readiness or external conformance claims. Report security concerns through an established private channel to the maintainer of the checkout; no security mailbox or hosted reporting service is provisioned by this package.

View identity, filter fingerprints, schema versions and epochs prevent accidental cursor-context reuse; they are not access tokens. The host authorizes every bootstrap/delta request and controls token serialization. View definitions and validators are trusted application code. On process restart or history reset, invalidate old sessions and rotate the epoch.

## What a transport must bind

The engine checks protocol correctness. It has no notion of who is calling, so
two identifiers that arrive from a client are authority the engine will honour
without question. A transport that forwards either one unbound is broken, and
both failures are silent.

**Replica identity.** `Mutation::$replica` selects an acknowledgement stream.
Anyone who can name another device's replica can claim its sequence numbers:
that device's next push is answered `sequence_behind` and renumbered, and its
writes interleave with the intruder's in one stream. Namespace the replica under the
authenticated principal, and namespace the mutation id with it: a client-chosen
global mutation id also lets one caller burn an id another is about to use.

Bind on a **stable** account identifier, never a session or token id.
`Engine::process()` compares the stored `Provenance::$actorId` on replay, so an
identifier that rotates at re-login turns every legitimate retry into a terminal
protocol error.

**Bootstrap tokens and view cursors.** Both name the space they read.
`ViewSyncService::bootstrap()` requires the caller to supply the context it
expects and refuses anything else, so the space is asserted from the session
rather than taken from the token. `delta()` checks the cursor's schema, epoch,
view and filter against the view it is given, but takes the space from the
cursor: the transport compares `$cursor->context` with what the session may read
before calling it. That is the *only* check the engine can make. Whether this caller may read that space at all is the
transport's decision, and a token remains a bearer credential for whoever holds
it until the epoch rotates.

**Field visibility.** A view filters rows, not columns. A projected
`EntityRecord` carries every field, and each field's `origin` carries a
`Provenance` naming the actor who wrote it. Serializing a record without an
explicit field whitelist discloses both the values and their authorship.
