<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire\Database;

use Illuminate\Support\ConfigurationUrlParser;
use LedgeHQ\Ledge\Acquire\AcquireException;
use Spatie\DbDumper\Databases\MariaDb;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\PostgreSql;
use Spatie\DbDumper\Databases\Sqlite;
use Spatie\DbDumper\DbDumper;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Dumps a Laravel database connection with spatie/db-dumper, which shells
 * out to mysqldump / mariadb-dump / pg_dump / sqlite3.
 */
final class SpatieDatabaseDumper implements DatabaseDumper
{
    private const BINARIES = [
        'mysql' => 'mysqldump',
        'mariadb' => 'mariadb-dump',
        'pgsql' => 'pg_dump',
        'sqlite' => 'sqlite3',
    ];

    /** @var array<string, mixed> */
    private readonly array $connection;

    /**
     * @param  array<string, mixed>  $connection  a `database.connections.*` config array
     * @param  string|null  $dumpBinaryPath  directory holding the dump binary when it is not on PATH
     */
    public function __construct(
        array $connection,
        private readonly ?string $dumpBinaryPath = null,
        private readonly int $timeout = 3600,
    ) {
        $this->connection = (new ConfigurationUrlParser)->parseConfiguration($connection);
    }

    public function isAvailable(): bool
    {
        $binary = self::BINARIES[$this->driver()] ?? null;

        if ($binary === null) {
            return false;
        }

        if ($this->dumpBinaryPath !== null) {
            return is_executable(rtrim($this->dumpBinaryPath, '/').'/'.$binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }

    public function dump(string $path): void
    {
        $dumper = $this->dumper();

        try {
            $dumper->dumpToFile($path);
        } catch (Throwable $e) {
            throw new AcquireException('dump_failed', 500, $e->getMessage());
        }
    }

    private function dumper(): DbDumper
    {
        $driver = $this->driver();

        $dumper = match ($driver) {
            'mysql' => new MySql,
            'mariadb' => new MariaDb,
            'pgsql' => new PostgreSql,
            'sqlite' => new Sqlite,
            default => throw new AcquireException('dump_failed', 500, "unsupported database driver \"{$driver}\""),
        };

        $database = (string) ($this->connection['database'] ?? '');

        if ($database === '' || $database === ':memory:') {
            throw new AcquireException('dump_failed', 500, 'the connection has no dumpable database');
        }

        $dumper->setDbName($database)->setTimeout($this->timeout);

        if ($this->dumpBinaryPath !== null) {
            $dumper->setDumpBinaryPath($this->dumpBinaryPath);
        }

        if ($driver === 'sqlite') {
            return $dumper;
        }

        $dumper->setUserName((string) ($this->connection['username'] ?? ''));
        $dumper->setPassword((string) ($this->connection['password'] ?? ''));

        if (! empty($this->connection['host'])) {
            $dumper->setHost((string) $this->connection['host']);
        }

        if (! empty($this->connection['port'])) {
            $dumper->setPort((int) $this->connection['port']);
        }

        if (! empty($this->connection['unix_socket'])) {
            $dumper->setSocket((string) $this->connection['unix_socket']);
        }

        return $dumper;
    }

    private function driver(): string
    {
        return (string) ($this->connection['driver'] ?? '');
    }
}
