<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/FreshRunSafety.php';

/**
 * Proves that the fresh-run safety boundary rejects unsafe, ambiguous,
 * or production/test DB targets before any write can occur.
 *
 * These tests do NOT require a live DB — they validate the safety logic only.
 */
class FreshRunSafetyTest extends TestCase
{
    private string $origSimMode;
    private string $origDestructive;

    protected function setUp(): void
    {
        // Capture original env so tearDown can restore
        $this->origSimMode = getenv(FreshRunSafety::ENV_SIMULATION_MODE) ?: '';
        $this->origDestructive = getenv(FreshRunSafety::ENV_DESTRUCTIVE_RESET) ?: '';
    }

    protected function tearDown(): void
    {
        if ($this->origSimMode !== '') {
            putenv(FreshRunSafety::ENV_SIMULATION_MODE . '=' . $this->origSimMode);
        } else {
            putenv(FreshRunSafety::ENV_SIMULATION_MODE);
        }
        if ($this->origDestructive !== '') {
            putenv(FreshRunSafety::ENV_DESTRUCTIVE_RESET . '=' . $this->origDestructive);
        } else {
            putenv(FreshRunSafety::ENV_DESTRUCTIVE_RESET);
        }
    }

    private function setFreshRunEnv(bool $destructive = false): void
    {
        putenv(FreshRunSafety::ENV_SIMULATION_MODE . '=fresh-run');
        if ($destructive) {
            putenv(FreshRunSafety::ENV_DESTRUCTIVE_RESET . '=1');
        } else {
            putenv(FreshRunSafety::ENV_DESTRUCTIVE_RESET);
        }
    }

    // -----------------------------------------------------------------------
    // Simulation mode flag
    // -----------------------------------------------------------------------

    public function testRejectsWhenSimulationModeUnset(): void
    {
        putenv(FreshRunSafety::ENV_SIMULATION_MODE);
        $errors = FreshRunSafety::validate('127.0.0.1', '3306', 'tmc_sim_test', 'root');
        $this->assertNotEmpty($errors, 'Should reject when TMC_SIMULATION_MODE is unset');
        $this->assertStringContainsString('TMC_SIMULATION_MODE', $errors[0]);
    }

    public function testRejectsWhenSimulationModeWrongValue(): void
    {
        putenv(FreshRunSafety::ENV_SIMULATION_MODE . '=export');
        $errors = FreshRunSafety::validate('127.0.0.1', '3306', 'tmc_sim_test', 'root');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('fresh-run', $errors[0]);
    }

    public function testAcceptsWhenSimulationModeIsFreshRun(): void
    {
        $this->setFreshRunEnv();
        $errors = FreshRunSafety::validate('127.0.0.1', '3306', 'tmc_sim_test', 'root');
        $this->assertEmpty($errors, implode('; ', $errors));
    }

    // -----------------------------------------------------------------------
    // Destructive reset flag
    // -----------------------------------------------------------------------

    public function testRejectsDestructiveResetWithoutFlag(): void
    {
        $this->setFreshRunEnv(false);
        $errors = FreshRunSafety::validate('127.0.0.1', '3306', 'tmc_sim_test', 'root', true);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('TMC_FRESH_RUN_DESTRUCTIVE_RESET', $errors[0] . ($errors[1] ?? ''));
    }

    public function testAcceptsDestructiveResetWithFlag(): void
    {
        $this->setFreshRunEnv(true);
        $errors = FreshRunSafety::validate('127.0.0.1', '3306', 'tmc_sim_test', 'root', true);
        $this->assertEmpty($errors, implode('; ', $errors));
    }

    // -----------------------------------------------------------------------
    // Protected DB names
    // -----------------------------------------------------------------------

    /** @dataProvider protectedDbNamesProvider */
    public function testRejectsProtectedDbNames(string $dbName): void
    {
        $this->setFreshRunEnv();
        $errors = FreshRunSafety::validate('127.0.0.1', '3306', $dbName, 'root');
        $this->assertNotEmpty($errors, "Should reject protected DB name: $dbName");
    }

    public static function protectedDbNamesProvider(): array
    {
        return [
            ['too_many_coins'],
            ['too_many_coins_test'],
            ['too_many_coins_live'],
            ['too_many_coins_staging'],
            ['tmc_production'],
            ['tmc_live'],
        ];
    }

    // -----------------------------------------------------------------------
    // DB name allowlist
    // -----------------------------------------------------------------------

    /** @dataProvider safeDbNamesProvider */
    public function testAcceptsSafeDbNames(string $dbName): void
    {
        $this->setFreshRunEnv();
        $errors = FreshRunSafety::validate('127.0.0.1', '3306', $dbName, 'root');
        $this->assertEmpty($errors, "Should accept safe DB name: $dbName — " . implode('; ', $errors));
    }

    public static function safeDbNamesProvider(): array
    {
        return [
            ['tmc_sim_fresh'],
            ['tmc_sim_lifecycle_v1'],
            ['tmc_fresh_run_20260409'],
            ['tmc_test_sim_smoke'],
        ];
    }

    /** @dataProvider unsafeDbNamesProvider */
    public function testRejectsUnsafeDbNames(string $dbName): void
    {
        $this->setFreshRunEnv();
        $errors = FreshRunSafety::validate('127.0.0.1', '3306', $dbName, 'root');
        $this->assertNotEmpty($errors, "Should reject unsafe DB name: $dbName");
    }

    public static function unsafeDbNamesProvider(): array
    {
        return [
            ['mydb'],
            ['production_coins'],
            ['tmc_coins'],
            [''],
        ];
    }

    // -----------------------------------------------------------------------
    // Host safety
    // -----------------------------------------------------------------------

    /** @dataProvider safeHostsProvider */
    public function testAcceptsSafeHosts(string $host): void
    {
        $this->setFreshRunEnv();
        $errors = FreshRunSafety::validate($host, '3306', 'tmc_sim_test', 'root');
        $this->assertEmpty($errors, "Should accept safe host: $host — " . implode('; ', $errors));
    }

    public static function safeHostsProvider(): array
    {
        return [
            ['127.0.0.1'],
            ['localhost'],
            ['::1'],
        ];
    }

    /** @dataProvider unsafeHostsProvider */
    public function testRejectsUnsafeHosts(string $host): void
    {
        $this->setFreshRunEnv();
        $errors = FreshRunSafety::validate($host, '3306', 'tmc_sim_test', 'root');
        $this->assertNotEmpty($errors, "Should reject unsafe host: $host");
    }

    public static function unsafeHostsProvider(): array
    {
        return [
            ['srv1529799.hstgr.cloud'],
            ['192.168.1.100'],
            ['10.0.0.1'],
            ['db.example.com'],
            [''],
        ];
    }

    // -----------------------------------------------------------------------
    // Empty / missing required fields
    // -----------------------------------------------------------------------

    public function testRejectsEmptyDbPort(): void
    {
        $this->setFreshRunEnv();
        $errors = FreshRunSafety::validate('127.0.0.1', '', 'tmc_sim_test', 'root');
        $this->assertNotEmpty($errors);
    }

    public function testRejectsEmptyDbUser(): void
    {
        $this->setFreshRunEnv();
        $errors = FreshRunSafety::validate('127.0.0.1', '3306', 'tmc_sim_test', '');
        $this->assertNotEmpty($errors);
    }

    // -----------------------------------------------------------------------
    // isSafeDbName / isSafeHost unit checks
    // -----------------------------------------------------------------------

    public function testIsSafeDbNameReturnsFalseForEmptyString(): void
    {
        $this->assertFalse(FreshRunSafety::isSafeDbName(''));
    }

    public function testIsSafeHostReturnsFalseForEmptyString(): void
    {
        $this->assertFalse(FreshRunSafety::isSafeHost(''));
    }

    // -----------------------------------------------------------------------
    // Multiple errors accumulated
    // -----------------------------------------------------------------------

    public function testAccumulatesMultipleErrors(): void
    {
        putenv(FreshRunSafety::ENV_SIMULATION_MODE);
        $errors = FreshRunSafety::validate('db.example.com', '', 'too_many_coins', '');
        // Should have at least: sim mode, host, protected name, prefix, port, user
        $this->assertGreaterThanOrEqual(4, count($errors), 'Should accumulate multiple errors');
    }
}
