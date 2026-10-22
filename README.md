# Ledge for Laravel

Health, uptime, and compliance monitoring for Laravel applications. This package exposes a secured health endpoint that the [Ledge](https://ledgehq.app) service polls; it is the Laravel counterpart of the Craft CMS plugin and returns the same payload shape.

## Requirements

- PHP 8.3+ with the `sodium` and `zlib` extensions
- Laravel 13+

## Installation

```bash
composer require ledgehq/ledge-laravel
php artisan ledge:generate-key
```

The service provider is auto-discovered. `ledge:generate-key` writes `LEDGE_SECRET_KEY` to your `.env`; pass `--show` to print a key without writing it.

## Configuration

Requests must carry the secret in the `X-Ledge-Key` header:

```bash
curl -H "X-Ledge-Key: your-secret-key" https://your-app.com/_ledge/health
```

The header is the only accepted method by default. Set `LEDGE_ALLOW_QUERY_KEY=true` to also accept `?key=`; the header and parameter names are configurable with `LEDGE_KEY_HEADER` and `LEDGE_QUERY_KEY_PARAM`.

Every threshold, status level, path, and toggle has a `LEDGE_*` env var. Publish the annotated config to change defaults in code:

```bash
php artisan vendor:publish --tag=ledge-config
```

Thresholds come in `_degraded_at` / `_unhealthy_at` pairs, inclusive; set a tier to `null` (env: empty or `off`) to disable it. Condition checks (`debugMode`, missing env vars, unwritable storage, a stale scheduler) take a status level instead: `healthy`, `degraded`, or `unhealthy`. Invalid values are logged and replaced by the default. Disable checks with `LEDGE_DISABLED_CHECKS=scheduler,failedLogins` or the `enabled_checks` map.

Every check echoes the values it used under a `thresholds` key so the Ledge dashboard can show why a site tripped.

## Checks

| Check | What it reports |
|---|---|
| `database` | Connectivity and response time on the default connection |
| `queue` | Failed jobs within `LEDGE_QUEUE_FAILED_WINDOW`; pending and stale counts (exact on the `database` driver, pending only elsewhere) |
| `disk` | Usage of the storage path, plus an optional absolute free-bytes floor |
| `memory` | PHP memory usage against `memory_limit` |
| `debugMode` | `APP_DEBUG` on |
| `environment` | Variables listed in `.env.example` or read with `env()` in `config/` without a default that are not defined |
| `storage` | Writability of `storage/logs`, `storage/framework/*`, and `bootstrap/cache` |
| `scheduler` | Time since `schedule:run` last completed; silent until the first run is seen |
| `failedLogins` | Auth `Failed` events within `LEDGE_FAILED_LOGINS_WINDOW`, counted in the cache |

Add your own:

```php
use LedgeHQ\Ledge\Facades\Ledge;

Ledge::register(new MyCheck());   // implements LedgeHQ\Ledge\Checks\CheckInterface
Ledge::forget('failedLogins');
```

## Dependencies

`GET /_ledge/dependencies` returns the installed Composer package set (`{composer: [{name, version}], generatedAt, hash}`) for security-advisory matching, read from Composer's runtime data rather than the lockfile. The health payload carries the same `hash` as `dependenciesHash` and a `composerLockHash` of the raw `composer.lock`. Turn the endpoint off with `LEDGE_DEPENDENCIES_ENABLED=false`.

## Backups

With `LEDGE_ACQUIRE_ENABLED=true`, Ledge can command the app to dump its database, encrypt the dump to a one-time key, and upload it to Ledge's storage. Commands are Ed25519-signed by Ledge, verified against keys discovered from the Ledge service, replay-guarded, and restricted to allowlisted upload and callback hosts. The work runs as a queued job (`LedgeHQ\Ledge\Jobs\BackupJob`), so a queue worker must be running.

The dump uses `mysqldump`, `mariadb-dump`, `pg_dump`, or `sqlite3` via [spatie/db-dumper](https://github.com/spatie/db-dumper); point `LEDGE_ACQUIRE_DUMP_BINARY_PATH` at the directory holding the binary if it is not on the worker's `PATH`. To use a different dump pipeline, bind your own `LedgeHQ\Ledge\Acquire\Database\DatabaseDumper`.

See [docs/acquire-protocol.md](docs/acquire-protocol.md) for the protocol, every config option, and the failure reasons.

## Development

```bash
composer install
composer test
composer check-cs
composer phpstan
```
