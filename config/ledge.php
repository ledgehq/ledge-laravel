<?php

declare(strict_types=1);

/**
 * Ledge configuration
 *
 * Publish this file with `php artisan vendor:publish --tag=ledge-config`.
 *
 * Every option resolves in the same order:
 *
 *   1. an explicit value in this file
 *   2. the matching `LEDGE_*` env var (the key in SCREAMING_SNAKE_CASE, so
 *      `disk_unhealthy_at` reads `LEDGE_DISK_UNHEALTHY_AT`)
 *   3. the default shown below
 *
 * Thresholds come in `_degraded_at` / `_unhealthy_at` pairs. A check trips a
 * tier when its measured value is greater than or equal to that tier's value.
 * Set a tier to `null` (or its env var to empty / `off`) to disable it.
 *
 * Status-level options accept `'healthy'`, `'degraded'`, or `'unhealthy'`.
 * `'healthy'` means the condition is still reported in the check's data but
 * never affects the overall status.
 *
 * Every check echoes the values it actually used under a `thresholds` key in
 * its result, so the Ledge dashboard can show why a site tripped.
 *
 * Invalid values (an unknown status level, a percentage over 100, a negative
 * count) are logged and replaced by the default.
 */

return [
    // ---------------------------------------------------------------------
    // Authentication & endpoints
    // ---------------------------------------------------------------------

    // Shared secret expected in the key header.
    // Generate one with `php artisan ledge:generate-key`.
    'secret_key' => env('LEDGE_SECRET_KEY'),

    // Name of the request header that carries the shared secret.
    'key_header' => env('LEDGE_KEY_HEADER', 'X-Ledge-Key'),

    // Also accept the key as a query parameter. Off by default so the key
    // never lands in URLs or access logs.
    'allow_query_key' => env('LEDGE_ALLOW_QUERY_KEY', false),
    'query_key_param' => env('LEDGE_QUERY_KEY_PARAM', 'key'),

    // URI the health endpoint is served from (no leading slash).
    'health_path' => env('LEDGE_HEALTH_PATH', '_ledge/health'),

    // Installed Composer package inventory for security-advisory matching.
    // While off the route is not registered and both hashes are omitted from
    // the health payload.
    'dependencies_enabled' => env('LEDGE_DEPENDENCIES_ENABLED', true),
    'dependencies_path' => env('LEDGE_DEPENDENCIES_PATH', '_ledge/dependencies'),

    // ---------------------------------------------------------------------
    // Checks
    // ---------------------------------------------------------------------

    // Turn individual checks off by name. Keys not listed stay enabled.
    // Env alternative: LEDGE_DISABLED_CHECKS=scheduler,failedLogins
    'enabled_checks' => [
        // 'database' => true,
        // 'queue' => true,
        // 'disk' => true,
        // 'memory' => true,
        // 'debugMode' => true,
        // 'environment' => true,
        // 'storage' => true,
        // 'scheduler' => true,
        // 'failedLogins' => true,
        // 'cache' => true,
        // 'redis' => true,      // only reported when cache, queue, session, or broadcasting use Redis
        // 'horizon' => true,    // only reported when laravel/horizon is installed
        // 'optimized' => true,
        // 'phpVersion' => true,
        // 'packages' => true,
    ],
    'disabled_checks' => env('LEDGE_DISABLED_CHECKS'),

    // Your own checks, registered after the defaults. Each class implements
    // LedgeHQ\Ledge\Checks\CheckInterface and is resolved from the container.
    // Inline alternative: Ledge::check('name', fn () => CheckResult::healthy('name'))
    // in a service provider. Wrap spatie/laravel-health checks with
    // LedgeHQ\Ledge\Checks\SpatieHealthCheck::wrap().
    'checks' => [
        // App\Ledge\SearchIndexCheck::class,
    ],

    // Disk usage of the storage path, as a percentage used.
    'disk_degraded_at' => env('LEDGE_DISK_DEGRADED_AT', 80),
    'disk_unhealthy_at' => env('LEDGE_DISK_UNHEALTHY_AT', 90),

    // Absolute floor in bytes: unhealthy when free space drops below it.
    'disk_min_free_bytes' => env('LEDGE_DISK_MIN_FREE_BYTES'),

    // PHP memory usage as a percentage of memory_limit.
    'memory_degraded_at' => env('LEDGE_MEMORY_DEGRADED_AT', 75),
    'memory_unhealthy_at' => env('LEDGE_MEMORY_UNHEALTHY_AT', 90),

    // A job is stale once it has waited longer than this many seconds.
    'queue_stale_after' => env('LEDGE_QUEUE_STALE_AFTER', 300),

    // Failed jobs are counted within this many seconds (failed_jobs rows
    // persist until flushed, so an all-time count would never recover).
    'queue_failed_window' => env('LEDGE_QUEUE_FAILED_WINDOW', 86400),

    // Failed and stale job counts.
    'queue_failed_degraded_at' => env('LEDGE_QUEUE_FAILED_DEGRADED_AT'),
    'queue_failed_unhealthy_at' => env('LEDGE_QUEUE_FAILED_UNHEALTHY_AT', 1),
    'queue_stale_degraded_at' => env('LEDGE_QUEUE_STALE_DEGRADED_AT'),
    'queue_stale_unhealthy_at' => env('LEDGE_QUEUE_STALE_UNHEALTHY_AT', 1),

    // Failed login attempts, recorded from Laravel's auth Failed event.
    'failed_logins_window' => env('LEDGE_FAILED_LOGINS_WINDOW', 86400),
    'failed_logins_degraded_at' => env('LEDGE_FAILED_LOGINS_DEGRADED_AT', 10),
    'failed_logins_unhealthy_at' => env('LEDGE_FAILED_LOGINS_UNHEALTHY_AT', 50),

    // The scheduler is stale when `schedule:run` has not completed within this
    // many seconds. Nothing is reported until the first run is recorded.
    'scheduler_stale_after' => env('LEDGE_SCHEDULER_STALE_AFTER', 600),
    'scheduler_stale_status' => env('LEDGE_SCHEDULER_STALE_STATUS', 'degraded'),

    // Status when APP_DEBUG is on.
    'debug_mode_status' => env('LEDGE_DEBUG_MODE_STATUS', 'degraded'),

    // Status when a variable listed in .env.example, or read with env() in
    // config/ without a default, is not defined.
    'missing_env_vars_status' => env('LEDGE_MISSING_ENV_VARS_STATUS', 'degraded'),
    'ignored_env_vars' => env('LEDGE_IGNORED_ENV_VARS', []),

    // Status when a storage or bootstrap cache directory is not writable.
    'storage_unwritable_status' => env('LEDGE_STORAGE_UNWRITABLE_STATUS', 'unhealthy'),

    // Status when the default cache store fails a write/read roundtrip.
    'cache_failure_status' => env('LEDGE_CACHE_FAILURE_STATUS', 'unhealthy'),

    // Status when Redis is in use but does not answer PING.
    'redis_failure_status' => env('LEDGE_REDIS_FAILURE_STATUS', 'unhealthy'),

    // Horizon: no master supervisor running / a master is paused.
    'horizon_stopped_status' => env('LEDGE_HORIZON_STOPPED_STATUS', 'unhealthy'),
    'horizon_paused_status' => env('LEDGE_HORIZON_PAUSED_STATUS', 'degraded'),

    // Status when APP_ENV=production and config or routes are not cached.
    'not_optimized_status' => env('LEDGE_NOT_OPTIMIZED_STATUS', 'degraded'),

    // PHP branch in security-fixes-only phase / past end of life.
    'php_security_only_status' => env('LEDGE_PHP_SECURITY_ONLY_STATUS', 'degraded'),
    'php_eol_status' => env('LEDGE_PHP_EOL_STATUS', 'unhealthy'),

    // Status when any installed Composer package has a newer stable release.
    // The comparison is made by `php artisan ledge:packages:refresh`, which
    // is scheduled daily unless `packages_auto_refresh` is off; the health
    // poll only reads the stored result.
    'update_available_status' => env('LEDGE_UPDATE_AVAILABLE_STATUS', 'healthy'),
    'packages_auto_refresh' => env('LEDGE_PACKAGES_AUTO_REFRESH', true),

    // ---------------------------------------------------------------------
    // Acquire (scheduled backups)
    // ---------------------------------------------------------------------

    // Lets Ledge command this app to dump its database, encrypt the dump to a
    // per-run key, and upload it. Off by default; while off the acquire
    // routes are not registered. See docs/acquire-protocol.md.
    'acquire_enabled' => env('LEDGE_ACQUIRE_ENABLED', false),
    'acquire_path' => env('LEDGE_ACQUIRE_PATH', '_ledge/acquire'),

    // Hosts that bundle uploads and callbacks may be sent to. Replaces the
    // default (ledgehq.app and its subdomains) entirely when set.
    // Env alternative: LEDGE_ACQUIRE_ALLOWED_HOSTS=ledge.example.com,*.example.com
    'acquire_allowed_hosts' => env('LEDGE_ACQUIRE_ALLOWED_HOSTS', ['ledgehq.app', '*.ledgehq.app']),

    // Accept plain-http upload and callback URLs. Local development only.
    'acquire_allow_insecure' => env('LEDGE_ACQUIRE_ALLOW_INSECURE', false),

    // Compressed archive size cap (bytes) and the queued job's timeout
    // (seconds). Queue connection and name are the app defaults unless set.
    'acquire_max_bundle_bytes' => env('LEDGE_ACQUIRE_MAX_BUNDLE_BYTES', 524288000),
    'acquire_job_timeout' => env('LEDGE_ACQUIRE_JOB_TIMEOUT', 3600),
    'acquire_queue' => env('LEDGE_ACQUIRE_QUEUE'),
    'acquire_queue_connection' => env('LEDGE_ACQUIRE_QUEUE_CONNECTION'),

    // Where the dump and archive are staged. Defaults to storage/framework/ledge.
    'acquire_tmp_path' => env('LEDGE_ACQUIRE_TMP_PATH'),

    // Directory holding mysqldump / mariadb-dump / pg_dump / sqlite3 when it
    // is not on PATH.
    'acquire_dump_binary_path' => env('LEDGE_ACQUIRE_DUMP_BINARY_PATH'),

    // Base URL of the Ledge service used to discover command-signing keys.
    // Deliberately not read from the environment: it is a trust anchor. You
    // should not need to change this.
    'ledge_base_url' => 'https://my.ledgehq.app',
];
