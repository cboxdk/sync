# Security

This package is the sync engine: it decides ordering and conflicts, and stores the result. Durable storage is included and exercised against SQLite, MySQL 8 and PostgreSQL. It does NOT provide authentication, authorization or production operational controls - those belong to the host, and `cboxdk/laravel-sync` is where they are implemented for a Laravel application.

See the [threat model and limitations](docs/security/threat-model.md). Hosts must authenticate replica ownership and authorize spaces, entity types and fields before calling the engine or exposing receipts/feed data.

If you identify a security issue, contact the maintainer through an established private channel. This local project has no provisioned security mailbox, hosted reporting service or response-time commitment. Do not disclose real credentials or private synced records in a public report.
