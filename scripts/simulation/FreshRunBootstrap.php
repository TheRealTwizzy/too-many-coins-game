<?php
/**
 * Fresh-run disposable DB bootstrap, reset, and teardown.
 *
 * Creates a clean database from schema.sql + seed_data.sql + all migrations,
 * independent from production/test lane databases.
 *
 * SAFETY: Every public method runs FreshRunSafety::validateOrDie() before any
 * write operation. If the safety boundary rejects the target, the process
 * exits before any DDL or DML executes.
 */

require_once __DIR__ . '/FreshRunSafety.php';

class FreshRunBootstrap
{
    private string $dbHost;
    private string $dbPort;
    private string $dbName;
    private string $dbUser;
    private string $dbPass;
    private string $repoRoot;

    public function __construct(
        string $dbHost,
        string $dbPort,
        string $dbName,
        string $dbUser,
        string $dbPass,
        ?string $repoRoot = null
    ) {
        $this->dbHost = $dbHost;
        $this->dbPort = $dbPort;
        $this->dbName = $dbName;
        $this->dbUser = $dbUser;
        $this->dbPass = $dbPass;
        $this->repoRoot = $repoRoot ?? dirname(__DIR__, 2);
    }

    /**
     * Bootstrap a disposable DB: create it if needed, load schema + seed + migrations.
     *
     * If the DB already exists and $dropFirst is true, it will be dropped and recreated.
     * If the DB exists and $dropFirst is false, bootstrap is skipped with a message.
     *
     * @param bool $dropFirst  Drop the database first if it exists (requires destructive-reset flag)
     * @return array{status: string, steps: string[]}
     */
    public function bootstrap(bool $dropFirst = false): array
    {
        // Safety gate — exits on failure
        FreshRunSafety::validateOrDie(
            $this->dbHost,
            $this->dbPort,
            $this->dbName,
            $this->dbUser,
            $dropFirst
        );

        $steps = [];
        $pdo = $this->connectWithoutDb();

        // Check if DB already exists
        $dbExists = $this->databaseExists($pdo);

        if ($dbExists && !$dropFirst) {
            return [
                'status' => 'already_exists',
                'steps' => ['Database already exists. Use --drop-first or set TMC_FRESH_RUN_DESTRUCTIVE_RESET=1 to reset.'],
            ];
        }

        if ($dbExists && $dropFirst) {
            $safeName = $this->quoteIdentifier($this->dbName);
            $pdo->exec("DROP DATABASE $safeName");
            $steps[] = "Dropped existing database: {$this->dbName}";
        }

        // Create the database
        $safeName = $this->quoteIdentifier($this->dbName);
        $pdo->exec("CREATE DATABASE $safeName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $steps[] = "Created database: {$this->dbName}";

        // Connect to the new database
        $dbPdo = $this->connectToDb();

        // Load schema
        $schemaPath = $this->repoRoot . '/schema.sql';
        $this->loadSqlFile($dbPdo, $schemaPath);
        $steps[] = 'Loaded schema.sql';

        // Load seed data
        $seedPath = $this->repoRoot . '/seed_data.sql';
        $this->loadSqlFile($dbPdo, $seedPath);
        $steps[] = 'Loaded seed_data.sql';

        // Load base boost/drop migration (loaded by init_db.php before other migrations)
        $boostDropPath = $this->repoRoot . '/migration_boosts_drops.sql';
        if (file_exists($boostDropPath)) {
            $this->loadSqlFile($dbPdo, $boostDropPath);
            $steps[] = 'Loaded migration_boosts_drops.sql';
        }

        // Apply init_db.php ALTER TABLE additions (idempotent column adds)
        $alterSteps = $this->applyInitDbAlters($dbPdo);
        $steps = array_merge($steps, $alterSteps);

        // Load all root-level migration files in sorted order (excluding boosts_drops already loaded)
        $migrationSteps = $this->applyRootMigrations($dbPdo);
        $steps = array_merge($steps, $migrationSteps);

        // Load active migrations from migrations/active/
        $activeSteps = $this->applyActiveMigrations($dbPdo);
        $steps = array_merge($steps, $activeSteps);

        return [
            'status' => 'bootstrapped',
            'steps' => $steps,
        ];
    }

    /**
     * Teardown: drop the disposable database.
     *
     * Requires destructive-reset flag.
     *
     * @return array{status: string, steps: string[]}
     */
    public function teardown(): array
    {
        FreshRunSafety::validateOrDie(
            $this->dbHost,
            $this->dbPort,
            $this->dbName,
            $this->dbUser,
            true // destructive
        );

        $steps = [];
        $pdo = $this->connectWithoutDb();

        if ($this->databaseExists($pdo)) {
            $safeName = $this->quoteIdentifier($this->dbName);
            $pdo->exec("DROP DATABASE $safeName");
            $steps[] = "Dropped database: {$this->dbName}";
        } else {
            $steps[] = "Database does not exist: {$this->dbName}";
        }

        return [
            'status' => 'torn_down',
            'steps' => $steps,
        ];
    }

    /**
     * Check if the disposable database exists.
     */
    public function exists(): bool
    {
        // Safety read-only check still validates simulation mode
        $errors = FreshRunSafety::validate(
            $this->dbHost,
            $this->dbPort,
            $this->dbName,
            $this->dbUser,
            false
        );
        if (!empty($errors)) {
            return false;
        }

        $pdo = $this->connectWithoutDb();
        return $this->databaseExists($pdo);
    }

    /**
     * Get a PDO connection to the disposable simulation database.
     *
     * Safety: validates simulation mode before returning a connection.
     * Callers must not use this to connect to non-simulation databases.
     *
     * @return PDO
     */
    public function getConnection(): PDO
    {
        FreshRunSafety::validateOrDie(
            $this->dbHost,
            $this->dbPort,
            $this->dbName,
            $this->dbUser,
            false
        );

        return $this->connectToDb();
    }

    // --- Internal helpers ---

    private function connectWithoutDb(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4',
            $this->dbHost,
            $this->dbPort
        );
        return new PDO($dsn, $this->dbUser, $this->dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ]);
    }

    private function connectToDb(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->dbHost,
            $this->dbPort,
            $this->dbName
        );
        return new PDO($dsn, $this->dbUser, $this->dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ]);
    }

    private function databaseExists(PDO $pdo): bool
    {
        $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$this->dbName]);
        return $stmt->fetchColumn() !== false;
    }

    private function loadSqlFile(PDO $pdo, string $path): void
    {
        if (!file_exists($path)) {
            throw new RuntimeException("SQL file not found: $path");
        }
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("SQL file is empty or unreadable: $path");
        }
        $pdo->exec($sql);
    }

    /**
     * Apply the idempotent ALTER TABLE statements from init_db.php.
     * These add columns that may not be in schema.sql yet.
     */
    private function applyInitDbAlters(PDO $pdo): array
    {
        $steps = [];
        $alters = [
            ['season_participation', 'sigil_drops_total', 'INT NOT NULL DEFAULT 0'],
            ['season_participation', 'eligible_ticks_since_last_drop', 'BIGINT NOT NULL DEFAULT 0'],
            ['season_participation', 'pending_rng_sigil_drops', 'BIGINT NOT NULL DEFAULT 0'],
            ['season_participation', 'pending_pity_sigil_drops', 'BIGINT NOT NULL DEFAULT 0'],
            ['season_participation', 'sigil_next_delivery_tick', 'BIGINT NOT NULL DEFAULT 0'],
            ['season_participation', 'coins_fractional_fp', 'BIGINT NOT NULL DEFAULT 0'],
        ];

        foreach ($alters as [$table, $column, $definition]) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                $steps[] = "Added column $table.$column";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                    // Column already exists from schema.sql — fine
                } else {
                    throw $e;
                }
            }
        }

        return $steps;
    }

    /**
     * Apply root-level migration_*.sql files in sorted order.
     * Skips migration_boosts_drops.sql (already loaded separately).
     */
    private function applyRootMigrations(PDO $pdo): array
    {
        $steps = [];
        $pattern = $this->repoRoot . '/migration_*.sql';
        $files = glob($pattern);
        if (!$files) {
            return $steps;
        }
        sort($files);

        foreach ($files as $file) {
            $basename = basename($file);
            // Skip the one already loaded as part of init_db flow
            if ($basename === 'migration_boosts_drops.sql') {
                continue;
            }
            try {
                $this->loadSqlFile($pdo, $file);
                $steps[] = "Applied migration: $basename";
            } catch (PDOException $e) {
                if ($this->isIdempotentMigrationError($e)) {
                    $steps[] = "Migration $basename (idempotent skip): " . $e->getMessage();
                } else {
                    throw new RuntimeException(
                        "Migration $basename failed with unexpected error: " . $e->getMessage(),
                        (int) $e->getCode(),
                        $e
                    );
                }
            }
        }

        return $steps;
    }

    /**
     * Apply migrations from migrations/active/ in sorted order.
     */
    private function applyActiveMigrations(PDO $pdo): array
    {
        $steps = [];
        $activeDir = $this->repoRoot . '/migrations/active';
        if (!is_dir($activeDir)) {
            return $steps;
        }
        $pattern = $activeDir . '/*.sql';
        $files = glob($pattern);
        if (!$files) {
            return $steps;
        }
        sort($files);

        foreach ($files as $file) {
            $basename = basename($file);
            try {
                $this->loadSqlFile($pdo, $file);
                $steps[] = "Applied active migration: $basename";
            } catch (PDOException $e) {
                if ($this->isIdempotentMigrationError($e)) {
                    $steps[] = "Active migration $basename (idempotent skip): " . $e->getMessage();
                } else {
                    throw new RuntimeException(
                        "Active migration $basename failed with unexpected error: " . $e->getMessage(),
                        (int) $e->getCode(),
                        $e
                    );
                }
            }
        }

        return $steps;
    }

    /**
     * Check whether a PDOException from a migration is a known-idempotent pattern
     * that can be safely skipped (e.g., duplicate column, table already exists).
     */
    private function isIdempotentMigrationError(PDOException $e): bool
    {
        $msg = $e->getMessage();
        $patterns = [
            'Duplicate column',
            'Duplicate key',
            'already exists',
            'Duplicate entry',
        ];
        foreach ($patterns as $pattern) {
            if (stripos($msg, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Quote a MySQL identifier safely.
     * Only allows alphanumeric and underscore characters — rejects anything else.
     */
    private function quoteIdentifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new RuntimeException("Unsafe identifier rejected: $name");
        }
        return '`' . $name . '`';
    }
}
