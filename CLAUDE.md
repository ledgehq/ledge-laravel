# Ledge for Laravel

Health, uptime, and compliance monitoring package for Laravel 13+ applications. It is the Laravel counterpart of the `ledge-craft` plugin (sibling repo) and returns the same payload shape to the Ledge service. See `TODO.md` for decisions and open items and `docs/acquire-protocol.md` for the backup transport.

## Stack and tooling

- PHP 8.3+, Laravel 13+ only. Namespace `LedgeHQ\Ledge`, config key `ledge`.
- Composer is not on the host PATH. Download `composer.phar` (`curl -sS https://getcomposer.org/installer | php`) into a scratch directory and run it with `php`.
- `composer test` (PHPUnit 12 + Testbench 11), `composer check-cs` / `fix-cs` (Pint, laravel preset, `declare(strict_types=1)` enforced), `composer phpstan` (Larastan level 6, `src/` only). All three must pass before a commit; CI runs them on PHP 8.3 and 8.4.
- The test suite needs the `sqlite3` binary for the real-dump test; it is present on macOS and the CI runners.

## Layout

```
config/ledge.php            Annotated config; every option has a LEDGE_* env var except ledge_base_url
src/
  LedgeServiceProvider.php  Bindings, default checks (DEFAULT_CHECKS), profiles (DEFAULT_PROFILES), routes, schedule
  Settings.php              Validated view of config('ledge'); SPEC table drives coercion and defaults
  HealthService.php         Check registry + payload assembly
  AcquireService.php        Signed-command intake, profile registry, cache-backed run state
  DependenciesService.php   Composer package inventory + hashes (normalization must match Craft byte for byte)
  Checks/                   One class per check; CheckInterface, CheckResult, Thresholds, CallbackCheck, SpatieHealthCheck
  Acquire/                  Framework-free transport ported from ledge-craft; Database/ holds the dump contract
  Jobs/                     AbstractAcquireJob pipeline + BackupJob profile
  Http/                     HealthController, DependenciesController, AcquireController, VerifyLedgeKey middleware
  Console/                  ledge:generate-key, ledge:packages:refresh
  Support/                  Cache-backed stores and Packagist helpers
tests/Unit                  Plain PHPUnit, framework-free (Acquire/ mirrors the Craft tests)
tests/Feature               Testbench; TestCase sets the secret key and the sqlite `testing` connection
tests/Support               Signing and bundle-decrypt helpers
```

## Conventions

- Config keys are snake_case; `LEDGE_*` env names and JSON payload keys are camelCase and identical to the Craft plugin. Do not rename payload fields without a matching Craft change.
- Adding an option: add it to `Settings::SPEC` (type + default), a readonly property, the constructor assignment, and the annotated entry in `config/ledge.php` with its `env()` call. Status options use the `status` type; thresholds come in `_degraded_at` / `_unhealthy_at` pairs.
- A check implements `CheckInterface`, echoes the values it used under `thresholds`, catches its own `Throwable`s, and returns `null` to omit itself (redis and horizon do this when not in use). Register defaults in `LedgeServiceProvider::DEFAULT_CHECKS`.
- Nothing in a health poll may make an outbound HTTP request. Lookups that need the network run in a scheduled command and write a snapshot to the cache (`ledge:packages:refresh`).
- `ledge_base_url` is a trust anchor: file-config only, never `env()`, never derived from a request.
- Acquire profiles are a registry so the Craft 6 layer can add `full`. Keep `AbstractAcquireJob` free of profile-specific logic.
- Testbench `DefineEnvironment` attribute methods run before the class-level `defineEnvironment()`; if a test needs the opposite of the class default, give it its own test class.
- Commit messages: a short imperative subject, a body that says why, and the attribution trailer the session provides.
