<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge;

use LedgeHQ\Ledge\Checks\CheckResult;
use Psr\Log\LoggerInterface;

/**
 * Validated view of the `ledge` config array. Env fallbacks are wired in
 * config/ledge.php via env(); this class coerces the raw values (env vars
 * arrive as strings) and falls back to the default for anything invalid.
 */
final class Settings
{
    public const STATUS_LEVELS = [
        CheckResult::STATUS_HEALTHY,
        CheckResult::STATUS_DEGRADED,
        CheckResult::STATUS_UNHEALTHY,
    ];

    private const INVALID = "\0invalid";

    private const NULLABLE_TYPES = ['int', 'percent'];

    /**
     * option => [type, default]. Types: string, bool, int (nullable,
     * >= 0), percent (nullable, 0-100), seconds / positive (required, > 0),
     * status, list (an empty list falls back to the default).
     *
     * @var array<string, array{0: string, 1: mixed}>
     */
    private const SPEC = [
        'secret_key' => ['string', null],
        'key_header' => ['string', 'X-Ledge-Key'],
        'allow_query_key' => ['bool', false],
        'query_key_param' => ['string', 'key'],
        'health_path' => ['string', '_ledge/health'],
        'dependencies_enabled' => ['bool', true],
        'dependencies_path' => ['string', '_ledge/dependencies'],
        'disk_degraded_at' => ['percent', 80],
        'disk_unhealthy_at' => ['percent', 90],
        'disk_min_free_bytes' => ['int', null],
        'memory_degraded_at' => ['percent', 75],
        'memory_unhealthy_at' => ['percent', 90],
        'queue_stale_after' => ['seconds', 300],
        'queue_failed_window' => ['seconds', 86400],
        'queue_failed_degraded_at' => ['int', null],
        'queue_failed_unhealthy_at' => ['int', 1],
        'queue_stale_degraded_at' => ['int', null],
        'queue_stale_unhealthy_at' => ['int', 1],
        'failed_logins_window' => ['seconds', 86400],
        'failed_logins_degraded_at' => ['int', 10],
        'failed_logins_unhealthy_at' => ['int', 50],
        'scheduler_stale_after' => ['seconds', 600],
        'scheduler_stale_status' => ['status', CheckResult::STATUS_DEGRADED],
        'debug_mode_status' => ['status', CheckResult::STATUS_DEGRADED],
        'missing_env_vars_status' => ['status', CheckResult::STATUS_DEGRADED],
        'ignored_env_vars' => ['list', []],
        'storage_unwritable_status' => ['status', CheckResult::STATUS_UNHEALTHY],
        'acquire_enabled' => ['bool', false],
        'acquire_path' => ['string', '_ledge/acquire'],
        'acquire_allowed_hosts' => ['list', self::DEFAULT_ACQUIRE_ALLOWED_HOSTS],
        'acquire_allow_insecure' => ['bool', false],
        'acquire_max_bundle_bytes' => ['positive', 524288000],
        'acquire_job_timeout' => ['positive', 3600],
        'acquire_queue' => ['string', null],
        'acquire_queue_connection' => ['string', null],
        'acquire_tmp_path' => ['string', null],
        'acquire_dump_binary_path' => ['string', null],
        'ledge_base_url' => ['string', self::DEFAULT_LEDGE_BASE_URL],
        'checks' => ['list', []],
        'cache_failure_status' => ['status', CheckResult::STATUS_UNHEALTHY],
        'redis_failure_status' => ['status', CheckResult::STATUS_UNHEALTHY],
        'horizon_stopped_status' => ['status', CheckResult::STATUS_UNHEALTHY],
        'horizon_paused_status' => ['status', CheckResult::STATUS_DEGRADED],
        'not_optimized_status' => ['status', CheckResult::STATUS_DEGRADED],
        'php_security_only_status' => ['status', CheckResult::STATUS_DEGRADED],
        'php_eol_status' => ['status', CheckResult::STATUS_UNHEALTHY],
        'update_available_status' => ['status', CheckResult::STATUS_HEALTHY],
        'packages_auto_refresh' => ['bool', true],
    ];

    public const DEFAULT_LEDGE_BASE_URL = 'https://my.ledgehq.app';

    /** Prefix of this package's own env vars; they all have defaults and are never "missing". */
    public const ENV_PREFIX = 'LEDGE_';

    public const DEFAULT_ACQUIRE_ALLOWED_HOSTS = ['ledgehq.app', '*.ledgehq.app'];

    public readonly ?string $secretKey;

    public readonly string $keyHeader;

    public readonly bool $allowQueryKey;

    public readonly string $queryKeyParam;

    public readonly string $healthPath;

    public readonly bool $dependenciesEnabled;

    public readonly string $dependenciesPath;

    public readonly ?int $diskDegradedAt;

    public readonly ?int $diskUnhealthyAt;

    public readonly ?int $diskMinFreeBytes;

    public readonly ?int $memoryDegradedAt;

    public readonly ?int $memoryUnhealthyAt;

    public readonly int $queueStaleAfter;

    public readonly int $queueFailedWindow;

    public readonly ?int $queueFailedDegradedAt;

    public readonly ?int $queueFailedUnhealthyAt;

    public readonly ?int $queueStaleDegradedAt;

    public readonly ?int $queueStaleUnhealthyAt;

    public readonly int $failedLoginsWindow;

    public readonly ?int $failedLoginsDegradedAt;

    public readonly ?int $failedLoginsUnhealthyAt;

    public readonly int $schedulerStaleAfter;

    public readonly string $schedulerStaleStatus;

    public readonly string $debugModeStatus;

    public readonly string $missingEnvVarsStatus;

    /** @var list<string> */
    public readonly array $ignoredEnvVars;

    public readonly string $storageUnwritableStatus;

    public readonly bool $acquireEnabled;

    public readonly string $acquirePath;

    /** @var list<string> */
    public readonly array $acquireAllowedHosts;

    public readonly bool $acquireAllowInsecure;

    public readonly int $acquireMaxBundleBytes;

    public readonly int $acquireJobTimeout;

    public readonly ?string $acquireQueue;

    public readonly ?string $acquireQueueConnection;

    public readonly ?string $acquireTmpPath;

    public readonly ?string $acquireDumpBinaryPath;

    /**
     * Trust anchor for signing-key discovery. Deliberately file-config only:
     * config/ledge.php does not read it from env, and it is never derived
     * from an incoming command.
     */
    public readonly string $ledgeBaseUrl;

    /** @var list<string> check class names registered after the defaults */
    public readonly array $checks;

    public readonly string $cacheFailureStatus;

    public readonly string $redisFailureStatus;

    public readonly string $horizonStoppedStatus;

    public readonly string $horizonPausedStatus;

    public readonly string $notOptimizedStatus;

    public readonly string $phpSecurityOnlyStatus;

    public readonly string $phpEolStatus;

    public readonly string $updateAvailableStatus;

    public readonly bool $packagesAutoRefresh;

    /** @var array<string, bool> */
    private readonly array $enabledChecks;

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config, ?LoggerInterface $logger = null): self
    {
        return new self($config, $logger);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function __construct(array $config, ?LoggerInterface $logger)
    {
        $values = self::resolve($config, $logger);

        $this->secretKey = $values['secret_key'];
        $this->keyHeader = $values['key_header'];
        $this->allowQueryKey = $values['allow_query_key'];
        $this->queryKeyParam = $values['query_key_param'];
        $this->healthPath = $values['health_path'];
        $this->dependenciesEnabled = $values['dependencies_enabled'];
        $this->dependenciesPath = $values['dependencies_path'];
        $this->diskDegradedAt = $values['disk_degraded_at'];
        $this->diskUnhealthyAt = $values['disk_unhealthy_at'];
        $this->diskMinFreeBytes = $values['disk_min_free_bytes'];
        $this->memoryDegradedAt = $values['memory_degraded_at'];
        $this->memoryUnhealthyAt = $values['memory_unhealthy_at'];
        $this->queueStaleAfter = $values['queue_stale_after'];
        $this->queueFailedWindow = $values['queue_failed_window'];
        $this->queueFailedDegradedAt = $values['queue_failed_degraded_at'];
        $this->queueFailedUnhealthyAt = $values['queue_failed_unhealthy_at'];
        $this->queueStaleDegradedAt = $values['queue_stale_degraded_at'];
        $this->queueStaleUnhealthyAt = $values['queue_stale_unhealthy_at'];
        $this->failedLoginsWindow = $values['failed_logins_window'];
        $this->failedLoginsDegradedAt = $values['failed_logins_degraded_at'];
        $this->failedLoginsUnhealthyAt = $values['failed_logins_unhealthy_at'];
        $this->schedulerStaleAfter = $values['scheduler_stale_after'];
        $this->schedulerStaleStatus = $values['scheduler_stale_status'];
        $this->debugModeStatus = $values['debug_mode_status'];
        $this->missingEnvVarsStatus = $values['missing_env_vars_status'];
        $this->ignoredEnvVars = $values['ignored_env_vars'];
        $this->storageUnwritableStatus = $values['storage_unwritable_status'];
        $this->acquireEnabled = $values['acquire_enabled'];
        $this->acquirePath = $values['acquire_path'];
        $this->acquireAllowedHosts = $values['acquire_allowed_hosts'];
        $this->acquireAllowInsecure = $values['acquire_allow_insecure'];
        $this->acquireMaxBundleBytes = $values['acquire_max_bundle_bytes'];
        $this->acquireJobTimeout = $values['acquire_job_timeout'];
        $this->acquireQueue = $values['acquire_queue'];
        $this->acquireQueueConnection = $values['acquire_queue_connection'];
        $this->acquireTmpPath = $values['acquire_tmp_path'];
        $this->acquireDumpBinaryPath = $values['acquire_dump_binary_path'];
        $this->ledgeBaseUrl = rtrim($values['ledge_base_url'], '/');
        $this->checks = $values['checks'];
        $this->cacheFailureStatus = $values['cache_failure_status'];
        $this->redisFailureStatus = $values['redis_failure_status'];
        $this->horizonStoppedStatus = $values['horizon_stopped_status'];
        $this->horizonPausedStatus = $values['horizon_paused_status'];
        $this->notOptimizedStatus = $values['not_optimized_status'];
        $this->phpSecurityOnlyStatus = $values['php_security_only_status'];
        $this->phpEolStatus = $values['php_eol_status'];
        $this->updateAvailableStatus = $values['update_available_status'];
        $this->packagesAutoRefresh = $values['packages_auto_refresh'];

        $this->enabledChecks = self::resolveEnabledChecks($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function resolve(array $config, ?LoggerInterface $logger): array
    {
        $values = [];

        foreach (self::SPEC as $key => [$type, $default]) {
            $value = $default;

            if (array_key_exists($key, $config)) {
                $raw = $config[$key];
                $coerced = self::coerce($type, $raw);

                if ($coerced === self::INVALID) {
                    $logger?->warning("Ledge: invalid value for '{$key}', using the default", ['value' => $raw]);
                } elseif ($type === 'list') {
                    $value = $coerced === [] ? $default : $coerced;
                } elseif ($coerced !== null || in_array($type, self::NULLABLE_TYPES, true)) {
                    $value = $coerced;
                }
            }

            $values[$key] = $value;
        }

        return $values;
    }

    public function isCheckEnabled(string $name): bool
    {
        return $this->enabledChecks[$name] ?? true;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, bool>
     */
    private static function resolveEnabledChecks(array $config): array
    {
        $enabled = [];

        foreach ((array) ($config['enabled_checks'] ?? []) as $name => $flag) {
            $enabled[(string) $name] = self::coerce('bool', $flag) === true;
        }

        if ($enabled === []) {
            foreach (self::coerce('list', $config['disabled_checks'] ?? null) ?? [] as $name) {
                $enabled[$name] = false;
            }
        }

        return $enabled;
    }

    private static function coerce(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'string' => is_string($value) && $value !== '' ? $value : (is_string($value) ? null : self::INVALID),
            'bool' => self::coerceBool($value),
            'int' => self::coerceInt($value, 0, null),
            'percent' => self::coerceInt($value, 0, 100),
            'seconds', 'positive' => self::coerceInt($value, 1, null) ?? self::INVALID,
            'status' => is_string($value) && in_array(strtolower($value), self::STATUS_LEVELS, true) ? strtolower($value) : self::INVALID,
            'list' => self::coerceList($value),
            default => self::INVALID,
        };
    }

    private static function coerceBool(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (! is_string($value)) {
            return self::INVALID;
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => self::INVALID,
        };
    }

    private static function coerceInt(mixed $value, int $min, ?int $max): mixed
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if ($normalized === '' || $normalized === 'off' || $normalized === 'null' || $normalized === 'none') {
                return null;
            }

            if (! is_numeric($normalized) || (string) (int) $normalized !== $normalized) {
                return self::INVALID;
            }

            $value = (int) $normalized;
        }

        if (! is_int($value)) {
            return self::INVALID;
        }

        if ($value < $min || ($max !== null && $value > $max)) {
            return self::INVALID;
        }

        return $value;
    }

    private static function coerceList(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return self::INVALID;
        }

        $list = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                return self::INVALID;
            }

            $item = trim($item);

            if ($item !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }
}
