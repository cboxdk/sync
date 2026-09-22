---
title: "Persistence"
weight: 20
description: "Store the log durably, with gapless sequences and a proven concurrency design."
---

# Persistence

Two adapters ship. `Persistence\InMemoryStore` is the reference: fast, whole-state
inspectable, and gone at process exit. `Persistence\Pdo\PdoStore` is durable, and
runs on SQLite, MySQL 8.0.17+ and PostgreSQL.

```php
$connection = new PDO('pgsql:host=127.0.0.1;dbname=app', 'app', $password);
$store = new PdoStore($connection);
$store->migrate();

$engine = new Engine($store);
```

`migrate()` creates the tables if they are absent. A host that manages its own
migrations can read the statements from `PdoSchema::statements()` instead and
apply them however it prefers.

## Why writers in a space are serialized

Every mutation's first statement takes the space row's write lock
(`SELECT … FOR UPDATE`, or `BEGIN IMMEDIATE` on SQLite), before any read. That is
not incidental. The change feed promises two things an auto-increment column
cannot give you:

- **No gaps.** A sequence is consumed only by `Ledger::appendCommit()`. A replay,
  a mutation gap and any exception produce no commit and consume nothing. A
  database sequence burns numbers on rollback.
- **Numbering that matches visibility.** Without serialization, transaction A can
  reserve 10, B can commit 11, a reader advances past 11, and A then commits 10
  where no reader will ever look again.

The cost is one concurrent writer per space. A space is the consistency and
ordering boundary — typically a tenant, a workspace or a user — so that is where
the trade belongs. Writers in *different* spaces do not contend.

`bin/concurrency.php` runs this as a real experiment: several OS processes, each
with its own connection, writing one space, then checking the log is a gapless
ascending run and every replica is fully acknowledged.

```sh
php bin/concurrency.php --writers=6 --mutations=25 --dsn="pgsql:host=127.0.0.1;dbname=scratch" --user=app --password=secret
```

## What the schema stores as columns, and what it does not

Only what is queried gets a column: space, entity identity, record version,
tombstone flag, conflict group state, mutation identity, acknowledged sequence
and commit sequence. The immutable domain objects themselves are stored as opaque
payloads, so the schema never has to mirror every DTO.

Those payloads use PHP's own serialization, deflated and base64-encoded. That is a storage
detail of this adapter, not a wire format — nothing outside the database reads
them, and this package ships no transport. A cross-language representation is a
transport concern, and `Mutation::fingerprint()` has the same limitation today.

The base64 is not decoration: PHP encodes private and protected property names
with NUL bytes, which a PostgreSQL text column cannot hold at all. Keeping
payloads to a safe alphabet makes them identical on every driver rather than
working by accident on the permissive ones.

Deflating is what keeps the log affordable. A commit carries the record before
and after the write plus the receipt, and PHP's serialization repeats every class
and property name in full: a one-field edit on a ten-field record stored about
22KB, which is 20GiB per million writes per tenant. Deflated at level 3 it is
about 2.6KB, for roughly 20µs more per write. Level 3 rather than the default 6
because it gets within 15% of the size for a quarter of the CPU, and this runs on
every write. Reading inflates in bounded steps and refuses anything that would
expand past 16MB, because a stored row is not fully trusted and a few kilobytes
of deflate can expand to gigabytes.

Every payload carries a format version, written as a prefix: `2:` for deflated,
`1:` for the earlier uncompressed form, which is still read. Base64 cannot
contain a colon, so rows written before the tag existed are unambiguous and are
still read. The tag is what makes a future change of encoding say what happened,
instead of surfacing years later as an unserialize failure that reads like a
corrupt database. A payload from a newer format than the adapter understands is
refused by name rather than guessed at.

Decoding names the classes it accepts. PHP's `unserialize()` allows any class by
default, which turns any row someone can write — a restored backup, the replica
database sitting on an end-user's device, SQL injection anywhere else in the host
application — into an object-injection chain against whatever that application
has loaded. A payload naming anything outside the set the engine stores is
refused. It has to be refused rather than tolerated: an unlisted class decodes to
an incomplete object, and a nested one would otherwise pass a type check while
being unusable, arriving as a record whose fields quietly no longer work.

The exception is `sync_fields`, which exists purely so a view's field equality is
an index lookup rather than a scan. It stores a hash of the canonical field value,
not the value, which keeps equality exact without depending on any database's JSON
handling. Identity columns use a binary collation on every driver for the same
reason: MySQL's default collation is case- and accent-insensitive and would
otherwise page a bootstrap in a different order from SQLite and PostgreSQL.
On MySQL that collation is `utf8mb4_0900_bin`, not `utf8mb4_bin`: the latter
pads with spaces, so `a` and `a ` are the same key, and a mutation id differing
only by a trailing space was answered with another mutation's receipt. An
installation created by an earlier release is retyped by `migrate()` - the one
change reconciliation makes to an existing column, because it is a correctness
fix rather than a preference. **Run that first `migrate()` in a maintenance
window on a large MySQL installation:** changing the collation of a key column
cannot happen in place, so each table is copied with writes blocked - once per
table, all its columns together. Measured at about 1.7s per 130,000 rows. After
that it is a no-op. Every identifier is also capped at 150 characters
and may not contain a NUL byte, so no value can be stored on one driver and
refused by another.

Its index carries the keyset columns as well as the predicate, so one index both
finds the matching records and hands them over in order, and a selective page
costs the page rather than the space. Anchored on `sync_records` instead, the
planner probes the field table once per record: measured 331ms for one page of a
32,000-record space, against 0.2ms this way.

`sync_commits` carries the entity type of the commit as a column for a related
reason. A commit is one mutation on one entity, so a view bound to one entity
type can have the database skip another type's writes rather than decoding them
and discarding the result. Without it a view matching one type of a busy tenant
pays to decode every other type's writes on every poll, per device — measured at
255ms for a device catching up over 2,000 commits across ten types, against 7ms
with the column. It also stops the commit budget being spent on commits that
project nothing, which cut the same catch-up from twenty round trips to two.

Narrowing is a hint, never a filter the reader relies on. A commit stored before
the column existed has no type, and that is read as "unknown" rather than "does
not match" — the alternative silently drops history a device is owed. The view's
own `includes()` still decides membership, exactly as it does for a bootstrap.

## Keeping an installed schema current

`migrate()` is idempotent and also reconciles: it adds columns and indexes that
an existing installation is missing, and leaves everything else alone. Nothing is
dropped or retyped, and a column that is `NOT NULL` with no default is refused by
name rather than attempted, because adding one to a table that already holds rows
cannot work without a backfill the package cannot write for you.

This matters because `CREATE TABLE IF NOT EXISTS` does nothing to a table that
already exists. Without reconciliation an installation from an earlier release
would keep a schema the adapter can no longer write to, and find out on its first
write after the upgrade.

A Laravel host runs its migrations once, so `cboxdk/laravel-sync` ships a second
migration that re-runs the install for exactly this reason.

## Retention

Nothing is pruned automatically. `Store::prune($space, $from)` drops commits
below a sequence and moves the horizon; sequences keep their numbers. It is on
the contract, not only on the adapters, because the log is the only thing here
that grows without bound and a host had no supported way to reach it.
`cboxdk/laravel-sync` ships `php artisan sync:prune` over it. After that,
`retainedFrom()` reports the horizon, and a read below it raises
`Exceptions\HistoryUnavailable`, which the view service turns into
`ResetRequired(HistoryPruned)` so a client re-bootstraps instead of silently
skipping history.

Receipts go with the commits they were written in, so pruning bounds them too.
A receipt is what answers a replayed mutation; a device that lost a response and
stays away past the horizon is refused as reusing a sequence when it retries, and
has to treat that write as final without knowing how it ended. Keep enough
history to outlast the longest a device can be away with an unanswered push.
Receipts written before this column existed have no commit number and are kept.

What stays after pruning is bounded by your data, not by time: the live records
and their field index, open and resolved conflict groups, and one acknowledgement
row per device stream per space - which is what refuses a reused sequence at all.

## What is proven, and what is not

The whole test suite runs against five configurations from the same fixtures — in
memory, against a store that shares no objects across commits, against SQLite, and
against a real MySQL 8 and PostgreSQL — so the adapters are held to identical
behaviour rather than assumed to have it. The databases run in CI and on demand
through `SYNC_STORE=pdo` with a `SYNC_DSN`, along with the simulator and the
concurrency experiment. Six
concurrent writer processes against a real PostgreSQL and a real MySQL produce a
gapless log with every replica acknowledged.

Not proven here: crash durability under power loss, which depends on
`synchronous_commit` and `innodb_flush_log_at_trx_commit` being configured as the
host intends; lock-timeout tuning under heavy contention; and behaviour at
isolation levels other than each driver's default.
