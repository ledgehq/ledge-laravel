# Ledge for Laravel — TODO

Working notes. Review and prune.

## Decisions already made

- No shared `ledge-core` package. Craft 5.x / 4.x stay standalone copies; this package is the base layer for a future Craft 6 (Laravel) plugin, which will live on a `6.x` branch of `ledge-craft` and `require` this package.
- **Laravel 13+ and PHP 8.3+ only** (decided 2026-10-22). Laravel 13 shipped 2026-03-17 and requires PHP 8.3; Laravel 12 security fixes end 2027-02. Nobody is on this package yet, so there is no reason to carry 11/12 (or the PHP 8.2 conflict with `spatie/db-dumper` 4.x).
- The `full` acquire profile is not needed on Laravel. The `backup` profile (same signed transport, `dump.sql` only) is wanted and is implemented here. Profiles are a registry on `AcquireService` so the Craft 6 layer can add `full` with its own job class.
- **Database dumps go through `spatie/db-dumper`** (decided 2026-10-22) behind the package's own `DatabaseDumper` interface, so Craft 6 can substitute Craft's backup pipeline. `spatie/laravel-backup` was rejected: it is a whole product (destinations, cleanup, notifications, zip) and Ledge owns transport, encryption, and upload.
- **Health checks keep the package's own `CheckInterface` + `Ledge::register()` as the contract surface** (decided 2026-10-22), not `spatie/laravel-health`, whose result shape, storage, and notification model differ from the Ledge payload. A config-driven `checks` list, closure checks, and an optional adapter that wraps a `spatie/laravel-health` check are the planned extension points.
- Ledge-side update testing for Laravel apps = clone the repo, `composer update`, run the project's own test suite, report. Craft keeps the crawl strategy.
- Config keys are snake_case (Laravel idiom); `LEDGE_*` env names and the JSON payload stay identical to the Craft plugin.
- `dependenciesHash` normalization must stay byte-identical to Craft (test pins `c78b87ee…`).
- Breaking payload changes are fine right now (no installs yet). The health payload carries `capabilities` and `updateTest` alongside the older `acquireEnabled` / `backupProfileSupported` booleans until the Ledge service reads the new fields.

## Package

### Backup profile
- [x] Port the framework-free acquire transport from `ledge-craft/src/acquire` into `src/Acquire`. Laravel's cache repository replaces `yii\caching\CacheInterface`; the container's `GuzzleHttp\ClientInterface` replaces Craft's Guzzle factory.
- [x] Profile registry on `AcquireService` (`registerProfile(name, jobClass)`); base ships `['backup' => BackupJob::class]`, anything else is `invalid_profile`.
- [x] `POST /_ledge/acquire` + `GET /_ledge/acquire/{runId}` behind the shared key (header only, never the query param), gated by `acquire_enabled` (routes unregistered while off). No `web` group, so no CSRF.
- [x] `BackupJob` (`ShouldQueue`, extends `AbstractAcquireJob`): preflight → dump → gzip tar → seal to `bundle_pubkey` → PUT to `upload_url` → callbacks. Replay guard and run status in the cache, same TTLs as Craft.
- [x] `DatabaseDumper` interface + `SpatieDatabaseDumper` (MySQL, MariaDB, PostgreSQL, SQLite from the default connection's config) + `DatabaseInspector` (dump-size estimate and table count via `Schema::getTables()`).
- [x] Config: `acquire_enabled`, `acquire_path`, `acquire_allowed_hosts`, `acquire_allow_insecure`, `acquire_max_bundle_bytes`, `acquire_job_timeout`, `acquire_queue`, `acquire_queue_connection`, `acquire_tmp_path`, `acquire_dump_binary_path`, `ledge_base_url` (file-config only, never env, never derived from a command).
- [x] Real `acquireEnabled` / `backupProfileSupported` values plus `capabilities` and `updateTest` in the health payload.
- [x] `docs/acquire-protocol.md` copied from the Craft repo and trimmed to the backup profile.
- [ ] Exercise against a real MySQL and Postgres app (the test suite covers SQLite end to end; MySQL/Postgres only through the db-dumper configuration).
- [ ] Decide whether a failed job should retry. Today `tries = 1`: a replayed command is rejected, so Ledge re-issues a fresh `run_id` instead.

### Shared protocol fixtures (replaces what ledge-core would have given us)
- [ ] Create a fixtures set both repos vendor: signed command envelopes with expected verdicts, canonical-JSON input/output pairs, a keyset document, a known bundle + secret key that must decrypt. Same files, run through each repo's own implementation.
- [ ] Add the fixtures to `ledge-craft` too, and a note in both READMEs that protocol changes update the fixtures first.

### Checks
- [ ] Extension seams: `checks` list in `config/ledge.php` (class names, resolved from the container), closure checks (`Ledge::check('name', fn () => CheckResult::...)`), and an optional adapter for `spatie/laravel-health` checks.
- [ ] New checks common to Laravel apps: `cache` (read/write roundtrip on the default store), `redis` (ping when any connection uses it), `horizon` (status when `laravel/horizon` is installed), `optimized` (config/route/view cached when `APP_ENV=production`), `phpVersion` (EOL), and `packages` (below).
- [ ] `packages` check: outdated Composer packages via Packagist's `p2/{vendor}/{package}.json`, cached ~24h, with `updateAvailableStatus` / `criticalUpdateStatus` like Craft's `plugins` check. Include a `frameworkVersion` entry mirroring `craftVersion`. Do the lookup in a scheduled command and cache, not inline in the health poll.
- [ ] Queue: Horizon-aware stats when `laravel/horizon` is installed; Redis pending count is best-effort today and `stale` is null off the database driver. Decide whether that should be surfaced as a note in the payload.
- [ ] Queue: `queue_failed_window` is a deliberate divergence from Craft (which counts all failed jobs). Confirm the Ledge dashboard copy makes sense for both.
- [ ] Failed logins: cache buckets are lost on `cache:clear`. Consider an optional table-backed store, off by default.
- [ ] Environment: validate against a few real apps for false positives (optional keys listed in `.env.example` with blank values will be reported as *defined* if they exist in `.env`, but *missing* if the line is absent). Document `LEDGE_IGNORED_ENV_VARS`.
- [ ] Scheduler: confirm `schedule:work` and `schedule:run` under Forge/Vapor both fire `CommandFinished` with `command === 'schedule:run'`. Consider also listening to `ScheduledTaskFinished` as a fallback signal.
- [ ] Storage: probe write on the default filesystem disk? Skipped for now because a remote disk would cost a request per poll.
- [ ] `cpUrl` equivalent: Nova / Filament admin URL if detectable, else leave absent.

### Payload / contract (coordinate with the Ledge service)
- [ ] Ledge service reads `capabilities` (`["backup"]` here, `["backup","full"]` on Craft once ported) and `updateTest.strategy` (`testsuite` | `crawl`); then drop `backupProfileSupported` here and port `capabilities` to Craft 5.x.
- [ ] `platform.environment` is new (additive); make sure the service tolerates it.

### Tooling & release
- [ ] GitHub Actions: PHP 8.3 / 8.4 × Laravel 13 running `composer test`, `check-cs`, `phpstan`.
- [ ] Composer is not on the host PATH; sessions so far used a downloaded `composer.phar`. Add a DDEV config or a README note so `composer install` works the same way as `ledge-craft`.
- [ ] Decide how the package is distributed: public Packagist with `proprietary` license (as `ledge-craft` does) vs. a private Composer repo. Affects the Craft 6 plugin later (Plugin Store plugins can require any Packagist package).
- [ ] Tag `1.0.0`; date the CHANGELOG.
- [ ] Create a `ledgehq/test-laravel` app (like `test-craft-5`) to exercise the endpoints for real, including `config:cache`, `route:cache`, and a queued backup against MySQL.
- [ ] Add a `CLAUDE.md` for this repo (conventions differ from the Craft plugin: Pint, snake_case config, Testbench).

## Ledge service

- [ ] Accept `platform.name = "laravel"`; expect a different check set (no `license`, `plugins`, `formie`, `freeform`; new `storage`, `scheduler`, `packages`).
- [ ] Update-test runner for Laravel projects: clone, `composer update <targets>`, detect Pest vs PHPUnit from `composer.json`, run, report pass/fail + diff. Per-project "services needed" (MySQL, Redis) setting; optional `LEDGE_TEST_COMMAND` override on the site.
- [ ] Gate backup scheduling on the advertised capability rather than assuming.
- [ ] Integration tests against both a Craft and a Laravel test site so the two implementations are checked for drift where it matters.

## Craft (later)

- [ ] When Craft 6 is real: open a `6.x` branch of `ledge-craft` that requires this package and adds the Craft layer (plugin/license checks, `configVersion`, `cpUrl`, Formie/Freeform, Craft failed-login table, a `full` profile job extending `AbstractAcquireJob` with the manifest builders, a Craft `DatabaseDumper`). Add the remaining extension seams to this package in the same pass: platform provider, root-payload hook.
- [ ] Port the `capabilities` / `updateTest` payload fields to 5.x when the contract change ships.
