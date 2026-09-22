---
title: "Installation"
weight: 10
description: "Install the package and choose a store."
---

# Installation

```sh
composer require cboxdk/sync
```

The namespace is `Cbox\Sync`. There are no third-party runtime dependencies;
Pest, Pint and PHPStan are development tooling, and Laravel is not required. See
the generated [requirements](../requirements.md).

## Choose a store

```php
use Cbox\Sync\Engine;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Persistence\Pdo\PdoStore;

// Durable: SQLite 3.24+, MySQL 8.0.17+ or PostgreSQL 9.5+ (CI runs MySQL 8.4 and PostgreSQL 17).
$store = new PdoStore(new PDO('pgsql:host=127.0.0.1;dbname=app', 'app', $password));
$store->migrate();

// Or in memory, for tests and for exploring the protocol.
$store = new InMemoryStore;

$engine = new Engine($store);
```

Both stores are held to identical behaviour: the whole test suite runs against
each one from the same fixtures. See [persistence](../extension-points/persistence.md)
for the schema, the concurrency design and what is not proven.

Using this from Laravel? Take `cboxdk/laravel-sync` instead — it wires the engine
into the container and uses one of the application's own connections.

## Working on the package itself

```sh
git clone https://github.com/cboxdk/sync
composer install
composer qa
composer simulate
```
