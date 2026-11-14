<?php
/**
 * Too Many Coins - in-memory stand-in for Database, for DB-free selfchecks.
 *
 * It does not run SQL. It records every statement and answers fetch/fetchAll
 * from canned rows, which is enough to drive code paths that only need a few
 * reads and want their writes observed.
 *
 * The point of it is the "schema pending" case: code that lands before its
 * migration is applied (the documented production setting is
 * TMC_AUTO_SQL_MIGRATIONS=false). Mark columns missing and any statement that
 * names one throws the same PDOException MySQL would, while columnExists()
 * reports false - so a selfcheck can prove a path either guards the column or
 * fails the way production does.
 *
 *   $db = new FakeDb();
 *   $db->missingColumns = ['last_meaningful_economy_tick'];
 *   $db->onFetch('/FROM seasons/', ['season_id' => 1, ...]);
 *   ... call code under test with $db ...
 *   $db->statementsMatching('/UPDATE season_participation/');
 */

class FakeDbConnection {
    public $inTransaction = false;
    /** Raw statements run through exec() (SAVEPOINT and friends), in order. */
    public $execs = [];
    /** If set, exec() of a statement matching this regex throws. */
    public $failExecMatching = null;
    public function inTransaction(): bool {
        return $this->inTransaction;
    }
    public function exec($sql) {
        if ($this->failExecMatching !== null && preg_match($this->failExecMatching, (string)$sql)) {
            throw new PDOException("SQLSTATE[42000]: fake exec failure for: {$sql}");
        }
        $this->execs[] = (string)$sql;
        return 0;
    }
}

class FakeDb {
    /**
     * Column names that "do not exist yet". Matched as whole words anywhere
     * in the SQL, and columnExists() ignores its $table argument: fine for
     * globally unique names, but a common name (e.g. "status") would fail
     * statements on every table. Key by table before relying on that.
     */
    public $missingColumns = [];
    /** Every statement run, in order: ['sql' => string, 'params' => array]. */
    public $statements = [];
    /** [regex, row|null] pairs consulted in order by fetch(). */
    private $fetchRules = [];
    /** [regex, rows] pairs consulted in order by fetchAll(). */
    private $fetchAllRules = [];
    private $connection;
    public $commits = 0;
    public $rollbacks = 0;

    public function __construct() {
        $this->connection = new FakeDbConnection();
    }

    public function onFetch(string $regex, ?array $row): void {
        $this->fetchRules[] = [$regex, $row];
    }

    public function onFetchAll(string $regex, array $rows): void {
        $this->fetchAllRules[] = [$regex, $rows];
    }

    public function getConnection() {
        return $this->connection;
    }

    public function columnExists($table, $column) {
        return !in_array((string)$column, $this->missingColumns, true);
    }

    public function query($sql, $params = []) {
        $this->assertColumnsExist((string)$sql);
        $this->statements[] = ['sql' => (string)$sql, 'params' => $params];
        return new FakeDbStatement([]);
    }

    public function fetch($sql, $params = []) {
        $this->query($sql, $params);
        foreach ($this->fetchRules as [$regex, $row]) {
            if (preg_match($regex, (string)$sql)) {
                return $row === null ? false : $row;
            }
        }
        return false;
    }

    public function fetchAll($sql, $params = []) {
        $this->query($sql, $params);
        foreach ($this->fetchAllRules as [$regex, $rows]) {
            if (preg_match($regex, (string)$sql)) {
                return $rows;
            }
        }
        return [];
    }

    public function beginTransaction() {
        $this->connection->inTransaction = true;
    }

    public function commit() {
        $this->connection->inTransaction = false;
        $this->commits++;
    }

    public function rollback() {
        $this->connection->inTransaction = false;
        $this->rollbacks++;
    }

    public function lastInsertId() {
        return 1;
    }

    /** Statements whose SQL matches $regex. */
    public function statementsMatching(string $regex): array {
        return array_values(array_filter($this->statements, static function ($s) use ($regex) {
            return (bool)preg_match($regex, $s['sql']);
        }));
    }

    private function assertColumnsExist(string $sql): void {
        foreach ($this->missingColumns as $column) {
            if (preg_match('/\b' . preg_quote($column, '/') . '\b/', $sql)) {
                throw new PDOException(
                    "SQLSTATE[42S22]: Column not found: 1054 Unknown column '{$column}' in 'field list'"
                );
            }
        }
    }
}

class FakeDbStatement {
    private $rows;
    public function __construct(array $rows) {
        $this->rows = $rows;
    }
    public function fetch() {
        return array_shift($this->rows) ?? false;
    }
    public function fetchAll() {
        return $this->rows;
    }
    public function rowCount() {
        return 1;
    }
}
