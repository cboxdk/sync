# Security

This is an unreleased, in-memory correctness spike. It does not provide authentication, authorization, durable storage or production operational controls.

See the [threat model and limitations](docs/security/threat-model.md). Hosts must authenticate replica ownership and authorize spaces, entity types and fields before calling the engine or exposing receipts/feed data.

If you identify a security issue, contact the maintainer through an established private channel. This local project has no provisioned security mailbox, hosted reporting service or response-time commitment. Do not disclose real credentials or private synced records in a public report.
