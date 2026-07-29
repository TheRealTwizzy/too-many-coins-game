<?php
/**
 * Too Many Coins - Database Connection
 */
require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private static $migrationsChecked = false;
    private $pdo;

    private function __construct() {
        try {
            $pdoOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $pdoOptions[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
            }

            $this->pdo = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                $pdoOptions
            );
        } catch (PDOException $e) {
            if (PHP_SAPI === 'cli') {
                throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
            }
            die(json_encode(['error' => 'Database connection failed']));
        }

        try {
            $this->applyPendingMigrations();
        } catch (Throwable $e) {
            // Never block gameplay/API availability because of a migration script issue.
            error_log('Automatic migration startup warning: ' . $e->getMessage());
        }
    }

    private function applyPendingMigrations() {
        if (!defined('TMC_AUTO_SQL_MIGRATIONS') || !TMC_AUTO_SQL_MIGRATIONS) {
            return;
        }

        if (self::$migrationsChecked) {
            return;
        }

        if (!$this->isSchemaReadyForMigrations()) {
            self::$migrationsChecked = true;
            return;
        }

        $this->ensureMigrationTable();

        // Serialize the runner across processes and replicas.
        //
        // The per-migration check below is a check-then-act: two processes that
        // both miss the schema_migrations row both reach $this->pdo->exec($sql).
        // INSERT IGNORE de-duplicates the *bookkeeping*, not the *execution*, so a
        // rolling deploy (web + worker starting together) could apply the same
        // non-idempotent migration twice - silently double-applying tuning UPDATEs.
        // Same advisory-lock pattern the tick worker already uses.
        $lockRow = $this->fetch("SELECT GET_LOCK('tmc_migrations', 30) AS got_lock");
        if ((int)($lockRow['got_lock'] ?? 0) !== 1) {
            // Another process is migrating. Do not mark as checked: the next
            // connection retries and will find the work already done.
            error_log('[migrations] could not acquire tmc_migrations lock; deferring');
            return;
        }

        try {
            $this->applyMigrationFiles();
        } finally {
            $this->query("SELECT RELEASE_LOCK('tmc_migrations')");
        }

        self::$migrationsChecked = true;
    }

    /**
     * Applies each pending migration file. Callers must hold the
     * 'tmc_migrations' advisory lock.
     */
    private function applyMigrationFiles() {
        $files = $this->getAutoMigrationFiles();
        foreach ($files as $filePath) {
            $migrationName = basename($filePath);
            $checksum = hash_file('sha256', $filePath);
            if ($checksum === false) {
                throw new RuntimeException('Unable to hash migration file: ' . $migrationName);
            }

            $existing = $this->fetch(
                'SELECT checksum, status FROM schema_migrations WHERE migration_name = ?',
                [$migrationName]
            );

            if ($existing) {
                // Already recorded (applied or previously failed) – do not retry.
                // A previously-failed migration must be replaced by a new migration file;
                // editing the same file will trigger a checksum warning instead of a retry.
                if (($existing['checksum'] ?? '') !== $checksum) {
                    error_log('Migration checksum changed for ' . $migrationName . '. Create a new migration filename for new patches.');
                }
                continue;
            }

            $sql = file_get_contents($filePath);
            if ($sql === false) {
                throw new RuntimeException('Unable to read migration file: ' . $migrationName);
            }

            try {
                $this->pdo->exec($sql);
                // INSERT IGNORE: if a concurrent worker already recorded this migration
                // (race on multi-worker startup), silently skip the duplicate insert.
                $this->query(
                    'INSERT IGNORE INTO schema_migrations (migration_name, checksum, status) VALUES (?, ?, ?)',
                    [$migrationName, $checksum, 'applied']
                );
            } catch (Throwable $e) {
                error_log('Failed applying migration ' . $migrationName . ': ' . $e->getMessage());
                // Record the failure so this migration is not retried on every subsequent
                // request/worker-spawn, preventing log-spam and gameplay-path interference.
                // To fix: create a new migration file with corrected SQL.
                try {
                    $this->query(
                        'INSERT IGNORE INTO schema_migrations (migration_name, checksum, status) VALUES (?, ?, ?)',
                        [$migrationName, $checksum, 'failed']
                    );
                } catch (Throwable $recordErr) {
                    // If we cannot record the failure (e.g. table not ready), log and move on.
                    error_log('Could not record migration failure for ' . $migrationName . ': ' . $recordErr->getMessage());
                }
                continue;
            }
        }
    }

    private function isSchemaReadyForMigrations() {
        try {
            $exists = $this->fetch(
                "SELECT COUNT(*) AS c
                 FROM information_schema.tables
                 WHERE table_schema = ? AND table_name = 'server_state'",
                [DB_NAME]
            );
            return ((int)($exists['c'] ?? 0)) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function ensureMigrationTable() {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                checksum CHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'applied',
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
        );

        // Backfill the status column on pre-existing deployments that created this
        // table before the column was added.
        $col = $this->fetch(
            "SELECT 1 AS found FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'schema_migrations' AND COLUMN_NAME = 'status'",
            [DB_NAME]
        );
        if (!$col) {
            try {
                $this->pdo->exec(
                    "ALTER TABLE schema_migrations ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'applied'"
                );
            } catch (PDOException $e) {
                // In multi-worker startup, another worker may have added the column
                // between the information_schema probe and this ALTER TABLE.
                // MySQL reports this as SQLSTATE '42S21' / error code 1060
                // ("Duplicate column name"). Ignore that specific case; rethrow anything else.
                $code = $e->getCode();
                if ($code !== '42S21' && $code !== 1060 && $code !== '1060') {
                    throw $e;
                }
            }
        }
    }

    private function getAutoMigrationFiles() {
        $repoRoot = realpath(__DIR__ . '/..');
        if ($repoRoot === false) {
            return [];
        }

        $files = glob($repoRoot . DIRECTORY_SEPARATOR . 'migration_*.sql');
        if (!is_array($files)) {
            return [];
        }

        $autoFiles = [];
        foreach ($files as $filePath) {
            $fileName = basename($filePath);

            // Keep explicitly optional scripts manual-only.
            if (preg_match('/_optional\.sql$/i', $fileName)) {
                continue;
            }

            // Initial bootstrap migration is handled by init/setup paths.
            if (strcasecmp($fileName, 'migration_boosts_drops.sql') === 0) {
                continue;
            }

            $autoFiles[] = $filePath;
        }

        sort($autoFiles, SORT_NATURAL | SORT_FLAG_CASE);
        return $autoFiles;
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance() {
        self::$instance = null;
    }

    public function getConnection() {
        return $this->pdo;
    }

    /**
     * Does a column exist? Cached per process.
     *
     * Deployments are expected to run with TMC_AUTO_SQL_MIGRATIONS=false (the
     * documented production recommendation), so code can land before its
     * migration is applied. Any query that hard-references a brand-new column
     * would then be a fatal error, not a degraded feature - and for columns on
     * the lock-in and expiry paths that means the game's exit path breaks.
     * Guard with this and fall back.
     */
    private static $columnCache = [];
    public function columnExists($table, $column) {
        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }
        try {
            $row = $this->fetch(
                "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [(string)$table, (string)$column]
            );
            self::$columnCache[$key] = ((int)($row['c'] ?? 0)) > 0;
        } catch (Throwable $e) {
            self::$columnCache[$key] = false;
        }
        return self::$columnCache[$key];
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert($sql, $params = []) {
        $this->query($sql, $params);
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollback() {
        return $this->pdo->rollBack();
    }
}
