<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Migrations must say which database they change.
 *
 * This application's default connection is `clas` — the database shared with
 * the Yii application, 81 tables of it. Laravel's three stock migrations were
 * run once against this application's own schema, `clas_api`, which is where
 * the migration history lives. With the default pointing at the shared
 * database and nothing pinned, `php artisan migrate` would have created
 * `users`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`,
 * `sessions` and `password_reset_tokens` inside it — and reported success.
 *
 * So every migration declares its target with `protected $connection`, and
 * this holds that. A migration that inherits the connection from whatever
 * `.env` happens to say is one whose effect depends on where it is run.
 */
class MigrationSafetyTest extends TestCase
{
    /** The only connections a migration in this application may target. */
    private const ALLOWED = ['mysql', 'clas_api'];

    public function test_every_migration_declares_its_connection(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->migrationFiles() as $path) {
            $checked++;
            $migration = require $path;
            $connection = $migration->getConnection();

            if ($connection === null) {
                $offenders[] = basename($path).': no $connection declared';

                continue;
            }

            if (! in_array($connection, self::ALLOWED, true)) {
                $offenders[] = basename($path).": targets '{$connection}'";
            }
        }

        $this->assertGreaterThan(0, $checked, 'No migrations were checked.');

        $this->assertSame([], $offenders, implode(PHP_EOL, array_merge(
            ['These migrations do not state which database they change.'],
            ["Add protected \$connection = 'clas_api' for this application's own"],
            ["tables, or 'mysql' for the schema shared with the Yii application."],
            $offenders,
        )));
    }

    /**
     * The framework's own tables belong in this application's schema.
     *
     * Stated separately from the rule above because this is the specific
     * mistake that was available: these three are the migrations that would
     * have scattered framework tables through the shared database.
     */
    public function test_the_stock_migrations_target_this_applications_own_schema(): void
    {
        $stock = array_filter(
            $this->migrationFiles(),
            fn ($path) => str_starts_with(basename($path), '0001_01_01_'),
        );

        $this->assertNotEmpty($stock, 'The stock migrations have moved or been renamed.');

        foreach ($stock as $path) {
            $this->assertSame('clas_api', (require $path)->getConnection(),
                basename($path).' must not run against the shared database.');
        }
    }

    /**
     * The shared database is never the place for framework tables.
     *
     * A migration naming one of these while targeting `mysql` is the failure
     * this is here to catch, whatever its filename.
     */
    public function test_no_migration_creates_a_framework_table_in_the_shared_database(): void
    {
        $frameworkTables = [
            'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
            'jobs', 'job_batches', 'failed_jobs',
        ];

        $offenders = [];

        foreach ($this->migrationFiles() as $path) {
            if ((require $path)->getConnection() !== 'mysql') {
                continue;
            }

            $source = File::get($path);

            foreach ($frameworkTables as $table) {
                if (preg_match("/create\s*\(\s*['\"]{$table}['\"]/", $source)) {
                    $offenders[] = basename($path).": creates '{$table}' in the shared database";
                }
            }
        }

        $this->assertSame([], $offenders, implode(PHP_EOL, array_merge(
            ['The shared CLAS database must not hold this framework\'s tables.'],
            $offenders,
        )));
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        return array_map(
            fn ($file) => $file->getPathname(),
            File::files(database_path('migrations')),
        );
    }
}
