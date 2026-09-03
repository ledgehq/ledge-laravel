# Ledge for Laravel — TODO

Working notes from the 2026-09-03 scaffold session. Review and prune.

## Decisions already made

- No shared `ledge-core` package. Craft 5.x / 4.x stay standalone copies; this package is the base layer for a future Craft 6 (Laravel) plugin, which will live on a `6.x` branch of `ledge-craft` and `require` this package.
- The `full` acquire profile is not needed on Laravel. The `backup` profile (same signed transport, `dump.sql` only) is wanted.
- Ledge-side update testing for Laravel apps = clone the repo, `composer update`, run the project's own test suite, report. Craft keeps the crawl strategy.
- Config keys are snake_case (Laravel idiom); `LEDGE_*` env names and the JSON payload stay identical to the Craft plugin.
- `dependenciesHash` normalization must stay byte-identical to Craft (test pins `c78b87ee…`).

## Package

### Backup profile (next milestone)
- [ ] Port the framework-free acquire transport from `ledge-craft/src/acquire`: `CommandVerifier`, `KeyResolver`, `HostAllowlist`, `BundleBuilder`, `BundleUploader`, `CallbackClient`, `Preflight`, `TempWorkspace`, `BackupResultBuilder`, `AcquireException`, `AcquireCommand`. Swap `yii\caching\CacheInterface` for Laravel's cache repository, Craft's Guzzle factory for the container's `GuzzleHttp\Client`.
- [ ] Keep the supported profile list as data (registry on the service), not a constant, so the Craft 6 layer can add `full`. Base ships `['backup']`; a `full` command gets `invalid_profile`.
- [ ] `POST /_ledge/acquire` + `GET /_ledge/acquire/{runId}` behind the shared key, gated by `acquire_enabled` (routes unregistered while off). CSRF must not apply (no `web` group).
- [ ] `BackupJob` (`ShouldQueue`): preflight → dump → gzip tar → seal to `bundle_pubkey` → PUT to `upload_url` → callbacks. Replay guard and run status in the cache, same TTLs as Craft.
- [ ] Database dump: decide between `spatie/db-dumper` (MySQL/Postgres/SQLite, binary resolution built in) and shelling out to `mysqldump` / `pg_dump` directly. Either way expose a small dump interface so Craft 6 can substitute its own pipeline.
- [ ] Config: `acquire_enabled`, `acquire_path`, `acquire_allowed_hosts` (default `ledgehq.app`, `*.ledgehq.app`), `acquire_max_bundle_bytes`, `acquire_job_ttr`, `ledge_base_url` (file-config only, never env, never derived from a command).
- [ ] Flip `acquireEnabled` / `backupProfileSupported` in the health payload to real values once implemented.
- [ ] Copy `docs/acquire-protocol.md` from the Craft repo and trim to the backup profile.

### Shared protocol fixtures (replaces what ledge-core would have given us)
- [ ] Create a fixtures set both repos vendor: signed command envelopes with expected verdicts, canonical-JSON input/output pairs, a keyset document, a known bundle + secret key that must decrypt. Same files, run through each repo's own implementation.
- [ ] Add the fixtures to `ledge-craft` too, and a note in both READMEs that protocol changes update the fixtures first.

### Checks
- [ ] `packages` check: outdated Composer packages via Packagist's `p2/{vendor}/{package}.json`, cached ~24h, with `updateAvailableStatus` / `criticalUpdateStatus` like Craft's `plugins` check. Include a `frameworkVersion` entry mirroring `craftVersion`. Needs a decision on how much outbound HTTP a health poll may trigger (probably do the lookup in a scheduled command and cache, not inline).
- [ ] Queue: Horizon-aware stats when `laravel/horizon` is installed; Redis pending count is best-effort today and `stale` is null off the database driver. Decide whether that should be surfaced as a note in the payload.
- [ ] Queue: `queue_failed_window` is a deliberate divergence from Craft (which counts all failed jobs). Confirm the Ledge dashboard copy makes sense for both.
- [ ] Failed logins: cache buckets are lost on `cache:clear`. Consider an optional table-backed store, off by default.
- [ ] Environment: validate against a few real apps for false positives (optional keys listed in `.env.example` with blank values will be reported as *defined* if they exist in `.env`, but *missing* if the line is absent). Document `LEDGE_IGNORED_ENV_VARS`.
- [ ] Scheduler: confirm `schedule:work` and `schedule:run` under Forge/Vapor both fire `CommandFinished` with `command === 'schedule:run'`. Consider also listening to `ScheduledTaskFinished` as a fallback signal.
- [ ] Storage: probe write on the default filesystem disk? Skipped for now because a remote disk would cost a request per poll.
- [ ] `cpUrl` equivalent: Nova / Filament admin URL if detectable, else leave absent.

### Payload / contract (coordinate with the Ledge service)
- [ ] Replace the `backupProfileSupported` boolean with a `capabilities` list (`["backup"]` here, `["backup","acquire"]` on Craft) and an `updateTest: {strategy: "testsuite" | "crawl"}` hint. This is a contract change that also touches `ledge-craft` 5.x; keep the old boolean during the transition.
- [ ] `platform.environment` is new (additive); make sure the service tolerates it.

### Tooling & release
- [ ] GitHub Actions: matrix of PHP 8.2 / 8.3 / 8.4 × Laravel 11 / 12 / 13 running `composer test`, `check-cs`, `phpstan`. Local install resolved against Laravel 13 / Testbench 11 / PHPUnit 12 only.
- [ ] Composer is not on the host PATH; the scaffold used a downloaded `composer.phar`. Add a DDEV config or a note in the README so `composer install` works the same way as `ledge-craft`.
- [ ] Decide how the package is distributed: public Packagist with `proprietary` license (as `ledge-craft` does) vs. a private Composer repo. Affects the Craft 6 plugin later (Plugin Store plugins can require any Packagist package).
- [ ] Tag `1.0.0` once the backup profile lands; date the CHANGELOG.
- [ ] Create a `ledgehq/test-laravel` app (like `test-craft-5`) to exercise the endpoints for real, including `config:cache` and `route:cache`.
- [ ] Add a `CLAUDE.md` for this repo (conventions differ from the Craft plugin: Pint, snake_case config, Testbench).

## Ledge service

- [ ] Accept `platform.name = "laravel"`; expect a different check set (no `license`, `plugins`, `formie`, `freeform`; new `storage`, `scheduler`, `packages`).
- [ ] Update-test runner for Laravel projects: clone, `composer update <targets>`, detect Pest vs PHPUnit from `composer.json`, run, report pass/fail + diff. Per-project "services needed" (MySQL, Redis) setting; optional `LEDGE_TEST_COMMAND` override on the site.
- [ ] Gate backup scheduling on the advertised capability rather than assuming.
- [ ] Integration tests against both a Craft and a Laravel test site so the two implementations are checked for drift where it matters.

## Craft (later)

- [ ] When Craft 6 is real: open a `6.x` branch of `ledge-craft` that requires this package and adds the Craft layer (plugin/license checks, `configVersion`, `cpUrl`, Formie/Freeform, Craft failed-login table, `full` profile manifests). Add extension seams to this package in the same pass: platform provider, root-payload hook, profile registry, dump interface.
- [ ] Port the `capabilities` / `updateTest` payload fields to 5.x when the contract change ships.
