<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/FreshRunSafety.php';
require_once __DIR__ . '/../scripts/simulation/FreshRunBootstrap.php';

/**
 * Tests for the FreshRunBootstrap disposable DB lifecycle.
 *
 * NOTE: Tests that require a live MySQL connection are marked with @group freshdb
 * and will only pass when a local MySQL instance is available. They are skipped
 * by default unless TMC_FRESHDB_TEST_ENABLED=1 is set.
 *
 * Safety tests (no DB required) always run.
 */
class FreshRunBootstrapTest extends TestCase
{
    private string $origSimMode;
    private string $origDestructive;

    protected function setUp(): void
    {
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

    // -----------------------------------------------------------------------
    // Safety gate tests (no DB needed)
    // -----------------------------------------------------------------------

    /**
     * Run a fresh-run safety gate test in a subprocess via temp PHP file.
     * Returns [exitCode, output].
     */
    private function runSubprocess(string $phpCode): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tmc_freshrun_test_') . '.php';
        file_put_contents($tmpFile, "<?php\n$phpCode\n");
        exec('php ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
        @unlink($tmpFile);
        return [$exitCode, implode("\n", $output)];
    }

    private function safetyRequires(): string
    {
        $safetyPath = str_replace('\\', '/', __DIR__ . '/../scripts/simulation/FreshRunSafety.php');
        $bootstrapPath = str_replace('\\', '/', __DIR__ . '/../scripts/simulation/FreshRunBootstrap.php');
        return "require_once '$safetyPath';\nrequire_once '$bootstrapPath';\n";
    }

    /**
     * Proves that bootstrap aborts before any write when simulation mode is off.
     */
    public function testBootstrapAbortsWithoutSimulationMode(): void
    {
        [$exitCode] = $this->runSubprocess(
            'putenv("TMC_SIMULATION_MODE"); putenv("TMC_FRESH_RUN_DESTRUCTIVE_RESET");'
            . "\n" . $this->safetyRequires()
            . '$b = new FreshRunBootstrap("127.0.0.1","3306","tmc_sim_phpunit","root","");'
            . "\n" . '$b->bootstrap(false);'
        );
        $this->assertNotEquals(0, $exitCode, 'Bootstrap must abort (non-zero exit) when simulation mode is off');
    }

    /**
     * Proves that bootstrap aborts when targeting a protected DB name.
     */
    public function testBootstrapAbortsOnProtectedDbName(): void
    {
        [$exitCode] = $this->runSubprocess(
            'putenv("TMC_SIMULATION_MODE=fresh-run"); putenv("TMC_FRESH_RUN_DESTRUCTIVE_RESET=1");'
            . "\n" . $this->safetyRequires()
            . '$b = new FreshRunBootstrap("127.0.0.1","3306","too_many_coins","root","");'
            . "\n" . '$b->bootstrap(true);'
        );
        $this->assertNotEquals(0, $exitCode, 'Bootstrap must abort on protected DB name');
    }

    /**
     * Proves that bootstrap aborts when targeting a remote host.
     */
    public function testBootstrapAbortsOnRemoteHost(): void
    {
        [$exitCode] = $this->runSubprocess(
            'putenv("TMC_SIMULATION_MODE=fresh-run"); putenv("TMC_FRESH_RUN_DESTRUCTIVE_RESET=1");'
            . "\n" . $this->safetyRequires()
            . '$b = new FreshRunBootstrap("srv1529799.hstgr.cloud","3306","tmc_sim_test","root","");'
            . "\n" . '$b->bootstrap(false);'
        );
        $this->assertNotEquals(0, $exitCode, 'Bootstrap must abort on remote host');
    }

    /**
     * Proves that teardown aborts without destructive-reset flag.
     */
    public function testTeardownAbortsWithoutDestructiveFlag(): void
    {
        [$exitCode] = $this->runSubprocess(
            'putenv("TMC_SIMULATION_MODE=fresh-run"); putenv("TMC_FRESH_RUN_DESTRUCTIVE_RESET");'
            . "\n" . $this->safetyRequires()
            . '$b = new FreshRunBootstrap("127.0.0.1","3306","tmc_sim_phpunit","root","");'
            . "\n" . '$b->teardown();'
        );
        $this->assertNotEquals(0, $exitCode, 'Teardown must abort without destructive-reset flag');
    }

    // -----------------------------------------------------------------------
    // Live DB tests (requires local MySQL)
    // -----------------------------------------------------------------------

    /**
     * Full bootstrap/teardown cycle on a real local MySQL instance.
     *
     * @group freshdb
     */
    public function testFullBootstrapAndTeardownCycle(): void
    {
        if (getenv('TMC_FRESHDB_TEST_ENABLED') !== '1') {
            $this->markTestSkipped('Set TMC_FRESHDB_TEST_ENABLED=1 to run live DB tests');
        }

        $dbHost = getenv('TMC_FRESHDB_TEST_HOST') ?: '127.0.0.1';
        $dbPort = getenv('TMC_FRESHDB_TEST_PORT') ?: '3306';
        $dbName = 'tmc_sim_phpunit_' . getmypid();
        $dbUser = getenv('TMC_FRESHDB_TEST_USER') ?: 'root';
        $dbPass = getenv('TMC_FRESHDB_TEST_PASS') ?: '';

        // Fail-closed: freshdb tests must only target local hosts
        $localHosts = ['127.0.0.1', 'localhost', '::1'];
        $this->assertContains($dbHost, $localHosts,
            sprintf('freshdb tests require a local DB host. Got "%s" — refusing to connect to non-local target.', $dbHost));

        putenv(FreshRunSafety::ENV_SIMULATION_MODE . '=fresh-run');
        putenv(FreshRunSafety::ENV_DESTRUCTIVE_RESET . '=1');

        $bootstrap = new FreshRunBootstrap($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

        // Bootstrap fresh
        $result = $bootstrap->bootstrap(true);
        $this->assertEquals('bootstrapped', $result['status']);
        $this->assertTrue($bootstrap->exists());

        // Verify schema was loaded by checking for a known table
        $pdo = new PDO(
            "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
            $dbUser, $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('players', $tables);
        $this->assertContains('seasons', $tables);
        $this->assertContains('season_participation', $tables);
        $pdo = null;

        // Bootstrap again without drop — should report already_exists
        $result2 = $bootstrap->bootstrap(false);
        $this->assertEquals('already_exists', $result2['status']);

        // Teardown
        $result3 = $bootstrap->teardown();
        $this->assertEquals('torn_down', $result3['status']);
        $this->assertFalse($bootstrap->exists());
    }

    /**
     * Quote identifier rejects SQL injection attempts.
     */
    public function testQuoteIdentifierRejectsUnsafeChars(): void
    {
        putenv(FreshRunSafety::ENV_SIMULATION_MODE . '=fresh-run');
        putenv(FreshRunSafety::ENV_DESTRUCTIVE_RESET . '=1');

        // Use reflection to test private quoteIdentifier
        $bootstrap = new FreshRunBootstrap('127.0.0.1', '3306', 'tmc_sim_test', 'root', '');
        $ref = new ReflectionMethod($bootstrap, 'quoteIdentifier');
        $ref->setAccessible(true);

        // Safe names should pass
        $this->assertEquals('`tmc_sim_test`', $ref->invoke($bootstrap, 'tmc_sim_test'));

        // Unsafe names should throw
        $this->expectException(RuntimeException::class);
        $ref->invoke($bootstrap, 'tmc_sim; DROP DATABASE');
    }
}
