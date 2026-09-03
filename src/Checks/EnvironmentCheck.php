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
 * Flags environment variables the app expects but the environment does not
 * define. "Expected" is every key in `.env.example` plus every `env('X')`
 * call in `config/` with no default argument. "Defined" consults the real
 * process environment and the `.env` file directly, because with a cached
 * config Laravel never loads `.env` and `env()` would report everything as
 * missing.
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
            $expected = array_unique([...$this->keysFromExample(), ...$this->keysFromConfig()]);
            $defined = $this->definedKeys();
            $missingStatus = $this->settings->missingEnvVarsStatus;

            $missing = array_values(array_filter(
                $expected,
                fn (string $key): bool => ! isset($defined[$key]) && ! in_array($key, $this->settings->ignoredEnvVars, true),
            ));
            sort($missing);

            $meta = [
                'missing' => $missing,
                'expected' => count($expected),
                'php' => PHP_VERSION,
                'os' => php_uname('s').' '.php_uname('r'),
                'database' => $this->databaseVersion(),
                'thresholds' => ['missingEnvVarsStatus' => $missingStatus],
            ];

            if ($missing !== [] && $missingStatus !== CheckResult::STATUS_HEALTHY) {
                return new CheckResult($this->getName(), $missingStatus, $meta, count($missing).' environment variable(s) expected but not defined');
            }

            return CheckResult::healthy($this->getName(), $meta);
        } catch (Throwable $e) {
            $this->logger->error('Ledge environment check failed: '.$e->getMessage());

            return CheckResult::degraded($this->getName(), [], 'Check unavailable');
        }
    }

    /**
     * @return list<string>
     */
    private function keysFromExample(): array
    {
        $path = $this->app->basePath('.env.example');

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        return array_keys(Dotenv::parse((string) file_get_contents($path)));
    }

    /**
     * @return list<string>
     */
    private function keysFromConfig(): array
    {
        $keys = [];
        $files = glob($this->app->configPath().'/*.php') ?: [];

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            preg_match_all('/\benv\(\s*[\'"]([A-Z0-9_]+)[\'"]\s*\)/', $content, $matches);
            array_push($keys, ...$matches[1]);
        }

        return array_values(array_unique($keys));
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
