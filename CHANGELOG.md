# Release Notes for Ledge for Laravel

## Unreleased
- Requires PHP 8.3+ and Laravel 13+ (Laravel 11 and 12 are no longer targeted)
- Added the acquire capability with the `backup` profile: `POST /_ledge/acquire` accepts Ed25519-signed commands (keys discovered from the Ledge keyset, permanent pins, replay guard, host allowlist) and dispatches a `BackupJob` that dumps the default database connection through `spatie/db-dumper`, gzips, seals the archive to the command's `bundle_pubkey`, streams it to `upload_url`, and reports progress through bearer-authenticated callbacks; `GET /_ledge/acquire/{runId}` is the pull fallback. Off by default (`LEDGE_ACQUIRE_ENABLED`); profiles are a registry on `AcquireService` so a platform layer can add `full`. See `docs/acquire-protocol.md`
- The health payload now reports real `acquireEnabled` / `backupProfileSupported` values plus `capabilities` (registered profiles) and `updateTest: {strategy: "testsuite"}`
- The shared-key middleware rejects the query-parameter key on acquire routes regardless of `allow_query_key`, and answers there with `{accepted: false, reason: "invalid_key"}`
- Initial scaffold: key-authenticated `GET /_ledge/health` and `GET /_ledge/dependencies`, `ledge:generate-key`, and the `database`, `queue`, `disk`, `memory`, `debugMode`, `environment`, `storage`, `scheduler`, and `failedLogins` checks. Payload shape and `dependenciesHash` normalization match the Craft plugin
