<?php
/**
 * Too Many Coins - SQL migration discovery and application.
 *
 * One place for the rules every migration path shares: which files count,
 * the order they run in, and how a run is recorded in schema_migrations.
 * Used by the runtime auto-apply (Database::applyPendingMigrations), the
 * CLI runner (tools/migrate.php) and the read-only report
 * (tools/migration_status.php).
 *
 * Migration files live in migrations/ and are recorded by bare filename, so
 * rows written before the files moved out of the repo root still match and
 * nothing is re-applied because of the move.
 */

function tmc_migrations_dir(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'migrations';
}

/**
 * The migrations the automatic runner considers, as [filename => absolute path],
 * in natural case-insensitive filename order - the order they are applied in.
 *
 * Excluded on purpose:
 *  - *_optional.sql            manual-only by convention
 *  - migration_boosts_drops.sql bootstrap, owned by init_db.php and setup.sh
 */
function tmc_auto_migration_files(): array {
    $files = glob(tmc_migrations_dir() . DIRECTORY_SEPARATOR . 'migration_*.sql');
    if (!is_array($files)) {
        return [];
    }

    $auto = [];
    foreach ($files as $path) {
        $name = basename($path);
        if (preg_match('/_optional\.sql$/i', $name)) {
            continue;
        }
        if (strcasecmp($name, 'migration_boosts_drops.sql') === 0) {
            continue;
        }
        $auto[$name] = $path;
    }
    uksort($auto, 'strnatcasecmp');
    return $auto;
}

/**
 * Creates schema_migrations when missing and backfills the status column on
 * deployments that created the table before the column existed.
 */
function tmc_ensure_schema_migrations_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            checksum CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'applied',
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $stmt = $pdo->prepare(
        "SELECT 1 AS found FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations' AND COLUMN_NAME = 'status'"
    );
    $stmt->execute();
    if ($stmt->fetch()) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE schema_migrations ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'applied'");
    } catch (PDOException $e) {
        // In multi-worker startup another worker may have added the column
        // between the probe and this ALTER. MySQL reports that as SQLSTATE
        // '42S21' / error 1060 ("Duplicate column name"); anything else is real.
        $code = $e->getCode();
        if ($code !== '42S21' && $code !== 1060 && $code !== '1060') {
            throw $e;
        }
    }
}

/**
 * Serialises migration runs across processes and replicas (web + worker
 * starting together, or an operator running tools/migrate.php during a
 * redeploy). Same advisory-lock pattern the tick worker uses.
 */
function tmc_acquire_migration_lock(PDO $pdo, int $timeoutSeconds): bool {
    $row = $pdo->query("SELECT GET_LOCK('tmc_migrations', " . (int)$timeoutSeconds . ") AS got_lock")->fetch();
    return (int)($row['got_lock'] ?? 0) === 1;
}

function tmc_release_migration_lock(PDO $pdo): void {
    $pdo->query("SELECT RELEASE_LOCK('tmc_migrations')");
}

/**
 * Rows of schema_migrations keyed by migration_name. Empty when the table
 * does not exist yet, so callers can treat every file as pending.
 */
function tmc_recorded_migrations(PDO $pdo): array {
    $recorded = [];
    try {
        foreach ($pdo->query("SELECT migration_name, checksum, status, applied_at FROM schema_migrations") as $row) {
            $recorded[$row['migration_name']] = $row;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $recorded;
}

/**
 * Sorts $files (as returned by tmc_auto_migration_files) against the recorded
 * rows without touching the database:
 *
 *   pending  not recorded - a run would apply these, in this order
 *   applied  recorded with status 'applied'
 *   failed   recorded as failed - never retried; ship a new file instead
 *   drifted  recorded, but the file's checksum no longer matches
 */
function tmc_classify_migrations(array $files, array $recorded): array {
    $out = ['pending' => [], 'applied' => [], 'failed' => [], 'drifted' => []];
    foreach ($files as $name => $path) {
        if (!isset($recorded[$name])) {
            $out['pending'][] = $name;
            continue;
        }
        $row = $recorded[$name];
        if (($row['status'] ?? '') === 'failed') {
            $out['failed'][] = $name;
        } else {
            $out['applied'][] = $name;
        }
        if (($row['checksum'] ?? '') !== hash_file('sha256', $path)) {
            $out['drifted'][] = $name;
        }
    }
    return $out;
}

/**
 * Applies, in order, every file in $files that schema_migrations does not
 * already record, and records each outcome. Callers must hold the
 * tmc_migrations lock and have called tmc_ensure_schema_migrations_table.
 *
 * A recorded file - applied or failed - is skipped: a failed migration must be
 * replaced by a new file, and editing an applied file only produces a
 * checksum-drift warning, never a re-run. A failure is recorded with
 * status 'failed' so it is not retried on every request, and the run moves on
 * to the next file.
 *
 * $log receives one line per noteworthy event (the runtime passes error_log).
 * Returns ['applied' => [...], 'failed' => [...], 'skipped' => [...]].
 */
function tmc_apply_migration_files(PDO $pdo, array $files, ?callable $log = null): array {
    $log = $log ?? static function (string $line): void {};
    $result = ['applied' => [], 'failed' => [], 'skipped' => []];

    $lookup = $pdo->prepare('SELECT checksum, status FROM schema_migrations WHERE migration_name = ?');
    // INSERT IGNORE: if a concurrent runner already recorded this migration,
    // silently skip the duplicate bookkeeping row.
    $record = $pdo->prepare('INSERT IGNORE INTO schema_migrations (migration_name, checksum, status) VALUES (?, ?, ?)');

    foreach ($files as $migrationName => $filePath) {
        $checksum = hash_file('sha256', $filePath);
        if ($checksum === false) {
            throw new RuntimeException('Unable to hash migration file: ' . $migrationName);
        }

        $lookup->execute([$migrationName]);
        $existing = $lookup->fetch();
        $lookup->closeCursor();
        if ($existing) {
            if (($existing['checksum'] ?? '') !== $checksum) {
                $log('Migration checksum changed for ' . $migrationName . '. Create a new migration filename for new patches.');
            }
            $result['skipped'][] = $migrationName;
            continue;
        }

        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new RuntimeException('Unable to read migration file: ' . $migrationName);
        }

        try {
            $pdo->exec($sql);
            $record->execute([$migrationName, $checksum, 'applied']);
            $result['applied'][] = $migrationName;
        } catch (Throwable $e) {
            $log('Failed applying migration ' . $migrationName . ': ' . $e->getMessage());
            $result['failed'][] = $migrationName;
            try {
                $record->execute([$migrationName, $checksum, 'failed']);
            } catch (Throwable $recordErr) {
                $log('Could not record migration failure for ' . $migrationName . ': ' . $recordErr->getMessage());
            }
            continue;
        }
    }

    return $result;
}
