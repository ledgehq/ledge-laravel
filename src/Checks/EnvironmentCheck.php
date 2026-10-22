<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Dotenv\Dotenv;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use LedgeHQ\Ledge\Settings;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Flags environment variables the project declares but the environment does
 * not define. `.env.example` is the declaration: a key with a value
 * (`QUEUE_CONNECTION=database`) is required, a key left blank
 * (`AWS_ACCESS_KEY_ID=`) is optional and only listed for information.
 *
 * `env('X')` calls in `config/` are deliberately not treated as required:
 * Laravel's own skeleton reads dozens of optional services that way
 * (`env('AWS_ENDPOINT')`, `env('PUSHER_APP_KEY')`), so counting them makes
 * every app look misconfigured. This package's own `LEDGE_*` options always
 * have defaults and are never reported.
 *
 * "Defined" consults the real process environment and the `.env` file
 * directly, because with a cached config Laravel never loads `.env` and
 * `env()` would report everything as missing.
 */
class EnvironmentCheck implements CheckInterface
{
    public function __construct(
        private readonly Application $app,
        private readonly Settings $settings,
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'environment';
    }

    public function run(): ?CheckResult
    {
        try {
            $example = $this->exampleKeys();
            $defined = $this->definedKeys();
            $missingStatus = $this->settings->missingEnvVarsStatus;

            $undefined = fn (string $key): bool => ! isset($defined[$key]) && ! $this->isIgnored($key);

            $required = array_keys(array_filter($example, fn (bool $hasValue): bool => $hasValue));
            $missing = array_values(array_filter($required, $undefined));
            $optional = array_values(array_filter(array_keys(array_filter($example, fn (bool $hasValue): bool => ! $hasValue)), $undefined));
            sort($missing);
            sort($optional);

            $meta = [
                'missing' => $missing,
                'optional' => $optional,
                'expected' => count($required),
                'source' => $example === [] ? null : '.env.example',
                'php' => PHP_VERSION,
                'os' => php_uname('s').' '.php_uname('r'),
                'database' => $this->databaseVersion(),
                'thresholds' => ['missingEnvVarsStatus' => $missingStatus],
            ];

            if ($missing !== [] && $missingStatus !== CheckResult::STATUS_HEALTHY) {
                return new CheckResult($this->getName(), $missingStatus, $meta, count($missing).' environment variable(s) declared in .env.example but not defined');
            }

            return CheckResult::healthy($this->getName(), $meta);
        } catch (Throwable $e) {
            $this->logger->error('Ledge environment check failed: '.$e->getMessage());

            return CheckResult::degraded($this->getName(), [], 'Check unavailable');
        }
    }

    private function isIgnored(string $key): bool
    {
        return str_starts_with($key, Settings::ENV_PREFIX) || in_array($key, $this->settings->ignoredEnvVars, true);
    }

    /**
     * @return array<string, bool> key => whether the example gives it a value
     */
    private function exampleKeys(): array
    {
        $path = $this->app->basePath('.env.example');

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        return self::parseExample((string) file_get_contents($path));
    }

    /**
     * @return array<string, bool> key => whether the example gives it a value
     */
    public static function parseExample(string $contents): array
    {
        $keys = [];

        foreach (Dotenv::parse($contents) as $key => $value) {
            $keys[(string) $key] = trim((string) $value) !== '';
        }

        return $keys;
    }

    /**
     * @return array<string, true>
     */
    private function definedKeys(): array
    {
        $defined = [];

        foreach ([$_ENV, $_SERVER, getenv()] as $source) {
            foreach (array_keys($source) as $key) {
                $defined[(string) $key] = true;
            }
        }

        $envFile = $this->app->environmentFilePath();

        if (is_file($envFile) && is_readable($envFile)) {
            foreach (array_keys(Dotenv::parse((string) file_get_contents($envFile))) as $key) {
                $defined[$key] = true;
            }
        }

        return $defined;
    }

    private function databaseVersion(): ?string
    {
        try {
            $connection = $this->db->connection();
            $version = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

            return $connection->getDriverName().' '.(is_scalar($version) ? (string) $version : 'unknown');
        } catch (Throwable) {
            return null;
        }
    }
}
