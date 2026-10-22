<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\Database\DatabaseDumper;
use LedgeHQ\Ledge\Acquire\Database\DatabaseInspector;
use LedgeHQ\Ledge\Acquire\Database\SpatieDatabaseDumper;
use LedgeHQ\Ledge\Tests\TestCase;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Exercises the real db-dumper and schema-based inspector against a
 * file-backed SQLite database. MySQL and Postgres only differ in which
 * binary db-dumper invokes.
 */
final class DatabaseLayerTest extends TestCase
{
    private string $dbPath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->dbPath = sys_get_temp_dir().'/ledge-db-'.bin2hex(random_bytes(4)).'.sqlite';
        touch($this->dbPath);

        /** @var Application $app */
        $app['config']->set('database.default', 'file');
        $app['config']->set('database.connections.file', ['driver' => 'sqlite', 'database' => $this->dbPath, 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('users')->insert(['name' => 'ledge']);
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        @unlink($this->dbPath);

        parent::tearDown();
    }

    public function test_inspector_reports_table_count_and_a_size_estimate(): void
    {
        $inspector = $this->app->make(DatabaseInspector::class);

        self::assertSame(2, $inspector->countTables());
        self::assertGreaterThan(0, $inspector->estimateDumpBytes());
    }

    public function test_container_builds_the_dumper_from_the_default_connection(): void
    {
        if ((new ExecutableFinder)->find('sqlite3') === null) {
            self::markTestSkipped('sqlite3 is not installed');
        }

        $dumper = $this->app->make(DatabaseDumper::class);
        self::assertInstanceOf(SpatieDatabaseDumper::class, $dumper);
        self::assertTrue($dumper->isAvailable());

        $dumpPath = $this->dbPath.'.sql';
        $dumper->dump($dumpPath);
        $dump = (string) file_get_contents($dumpPath);
        @unlink($dumpPath);

        self::assertStringContainsString('CREATE TABLE', $dump);
        self::assertStringContainsString("'ledge'", $dump);
    }

    public function test_unsupported_driver_is_not_available(): void
    {
        self::assertFalse((new SpatieDatabaseDumper(['driver' => 'sqlsrv', 'database' => 'x']))->isAvailable());
        self::assertFalse((new SpatieDatabaseDumper(['driver' => 'mysql', 'database' => 'x'], '/nonexistent/bin'))->isAvailable());
    }

    public function test_in_memory_database_cannot_be_dumped(): void
    {
        try {
            (new SpatieDatabaseDumper(['driver' => 'sqlite', 'database' => ':memory:']))->dump('/tmp/never-written.sql');
            self::fail('Expected dump_failed');
        } catch (AcquireException $e) {
            self::assertSame('dump_failed', $e->reason);
        }
    }

    public function test_database_url_is_parsed_into_the_dumper(): void
    {
        $dumper = new SpatieDatabaseDumper(['driver' => 'mysql', 'url' => 'mysql://user:pass@db.internal:3307/app']);

        $reflection = new \ReflectionProperty($dumper, 'connection');
        $connection = $reflection->getValue($dumper);

        self::assertSame('db.internal', $connection['host']);
        self::assertSame('3307', (string) $connection['port']);
        self::assertSame('app', $connection['database']);
        self::assertSame('user', $connection['username']);
    }
}
