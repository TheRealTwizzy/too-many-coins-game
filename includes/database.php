<?php
/**
 * Too Many Coins - Database Connection
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/migrations.php';

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

        tmc_ensure_schema_migrations_table($this->pdo);

        // Serialize the runner across processes and replicas.
        //
        // The per-migration check is a check-then-act: two processes that both
        // miss the schema_migrations row both reach exec($sql). INSERT IGNORE
        // de-duplicates the *bookkeeping*, not the *execution*, so a rolling
        // deploy (web + worker starting together) could apply the same
        // non-idempotent migration twice - silently double-applying tuning
        // UPDATEs. Same advisory-lock pattern the tick worker already uses, and
        // tools/migrate.php takes the same lock.
        if (!tmc_acquire_migration_lock($this->pdo, 30)) {
            // Another process is migrating. Do not mark as checked: the next
            // connection retries and will find the work already done.
            error_log('[migrations] could not acquire tmc_migrations lock; deferring');
            return;
        }

        try {
            // Selection, order and bookkeeping live in includes/migrations.php,
            // shared with tools/migrate.php and tools/migration_status.php.
            tmc_apply_migration_files($this->pdo, tmc_auto_migration_files(), 'error_log');
        } finally {
            tmc_release_migration_lock($this->pdo);
        }

        self::$migrationsChecked = true;
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

    /**
     * True when a transaction is already open. Lets an operation that normally
     * manages its own transaction be composed into a larger one instead of
     * committing piecemeal - MySQL has no real nested transactions, so the
     * inner caller must defer to the outer.
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
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
