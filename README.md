# Cbox Sync

A framework-independent PHP sync engine for offline-first applications with one authoritative server and many replicas.

It merges independent field edits with atomic application by default, preserves complete competing proposals with trusted provenance, and supports explicit versioned conflict resolution. It includes strict revision preconditions, whole-state validation and contextual view/bootstrap references. Mutation ordering, idempotency, domain state, conflict candidates, results and acknowledgements share one storage transaction.

```sh
composer install
composer simulate
composer qa
```

The simulator delivers 100 competing edits in deterministic random orders, loses responses, retries requests, resolves candidates, delivers a late proposal and paginates whole commits. It fails if any checked invariant breaks.

Start with the [quickstart](docs/quickstart.md). See [architecture](docs/core-concepts/architecture.md), [protocol guarantees](docs/core-concepts/protocol.md), [conflict rules](docs/core-concepts/conflicts.md), [change feed](docs/core-concepts/change-feed.md), and [extension contracts](docs/extension-points/contracts.md).

Requires PHP `^8.4`, `ext-pdo` and `ext-zlib`, and for durable storage SQLite 3.24+, MySQL 8.0.17+ or PostgreSQL 9.5+; no third-party runtime dependencies or Laravel requirement. Development tooling is Pest, Pint and PHPStan at max level. [Requirements](docs/requirements.md) are generated from Composer metadata. CI targets PHP 8.4 and 8.5.

The default preserves proposals, not order-independent canonical state. Storage is durable via [PdoStore](docs/extension-points/persistence.md) on SQLite, MySQL 8.0.17+ and PostgreSQL, and a space accepts one concurrent writer by design. HTTP, authentication, Laravel/Eloquent, NativePHP and restore are outside this package. Read the [limitations](docs/security/threat-model.md) before integrating.

MIT, copyright Cbox. See [LICENSE](LICENSE), [SECURITY.md](SECURITY.md), and [BUILD-STATUS.md](BUILD-STATUS.md).
