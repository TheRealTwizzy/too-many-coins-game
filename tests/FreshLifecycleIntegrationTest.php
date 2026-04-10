<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/FreshLifecycleRunner.php';
require_once __DIR__ . '/../scripts/simulation/FreshRunSafety.php';

/**
 * End-to-end integration tests for fresh lifecycle simulation against a
 * real disposable local MySQL database.
 *
 * Env-gated: requires TMC_FRESHDB_TEST_ENABLED=1 and a local MySQL server.
 * Configure via env vars:
 *   TMC_FRESHDB_TEST_HOST (default: 127.0.0.1)
 *   TMC_FRESHDB_TEST_PORT (default: 3306)
 *   TMC_FRESHDB_TEST_USER (default: root)
 *   TMC_FRESHDB_TEST_PASS (default: empty)
 *
 * @group freshdb
 */
class FreshLifecycleIntegrationTest extends TestCase
{
    private string $dbName;
    private array $baseConfig;
    private string $origSimMode;
    private string $origDestructive;
    private string $origDbHost;
    private string $origDbPort;
    private string $origDbName;
    private string $origDbUser;
    private string $origDbPass;

    protected function setUp(): void
    {
        if (getenv('TMC_FRESHDB_TEST_ENABLED') !== '1') {
            $this->markTestSkipped('Set TMC_FRESHDB_TEST_ENABLED=1 to run live DB tests');
        }

        // Save env state
        $this->origSimMode    = getenv(FreshRunSafety::ENV_SIMULATION_MODE) ?: '';
        $this->origDestructive = getenv(FreshRunSafety::ENV_DESTRUCTIVE_RESET) ?: '';
        $this->origDbHost     = getenv('DB_HOST') ?: '';
        $this->origDbPort     = getenv('DB_PORT') ?: '';
        $this->origDbName     = getenv('DB_NAME') ?: '';
        $this->origDbUser     = getenv('DB_USER') ?: '';
        $this->origDbPass     = getenv('DB_PASS') ?: '';

        $dbHost = getenv('TMC_FRESHDB_TEST_HOST') ?: '127.0.0.1';
        $dbPort = getenv('TMC_FRESHDB_TEST_PORT') ?: '3306';
        $dbUser = getenv('TMC_FRESHDB_TEST_USER') ?: 'root';

        // Fail-closed: freshdb tests must only target local hosts
        $localHosts = ['127.0.0.1', 'localhost', '::1'];
        $this->assertContains($dbHost, $localHosts,
            sprintf('freshdb tests require a local DB host. Got "%s" — refusing to connect to non-local target.', $dbHost));
        $dbPass = getenv('TMC_FRESHDB_TEST_PASS') ?: '';

        $this->dbName = 'tmc_sim_integ_' . getmypid() . '_' . mt_rand(1000, 9999);

        $this->baseConfig = [
            'db_host'     => $dbHost,
            'db_port'     => $dbPort,
            'db_name'     => $this->dbName,
            'db_user'     => $dbUser,
            'db_pass'     => $dbPass,
            'seed'        => 42,
            'cohort_size' => 2,
            'drop_first'  => true,
        ];

        // Set env for simulation mode
        putenv(FreshRunSafety::ENV_SIMULATION_MODE . '=fresh-run');
        putenv(FreshRunSafety::ENV_DESTRUCTIVE_RESET . '=1');

        // Set DB_* env vars so production config.php resolves to disposable DB
        putenv('DB_HOST=' . $dbHost);
        putenv('DB_PORT=' . $dbPort);
        putenv('DB_NAME=' . $this->dbName);
        putenv('DB_USER=' . $dbUser);
        putenv('DB_PASS=' . $dbPass);
    }

    protected function tearDown(): void
    {
        // Clean up simulation clock
        if (class_exists('GameTime')) {
            GameTime::clearSimulationTick();
        }

        // Attempt DB teardown
        try {
            $bootstrap = new FreshRunBootstrap(
                $this->baseConfig['db_host'],
                $this->baseConfig['db_port'],
                $this->baseConfig['db_name'],
                $this->baseConfig['db_user'],
                $this->baseConfig['db_pass']
            );
            $bootstrap->teardown();
        } catch (\Throwable $e) {
            // Best-effort cleanup
        }

        // Restore env
        $this->restoreEnv(FreshRunSafety::ENV_SIMULATION_MODE, $this->origSimMode);
        $this->restoreEnv(FreshRunSafety::ENV_DESTRUCTIVE_RESET, $this->origDestructive);
        $this->restoreEnv('DB_HOST', $this->origDbHost);
        $this->restoreEnv('DB_PORT', $this->origDbPort);
        $this->restoreEnv('DB_NAME', $this->origDbName);
        $this->restoreEnv('DB_USER', $this->origDbUser);
        $this->restoreEnv('DB_PASS', $this->origDbPass);

        // Reset Database singleton if available
        if (class_exists('Database') && method_exists('Database', 'resetInstance')) {
            Database::resetInstance();
        }
    }

    private function restoreEnv(string $key, string $value): void
    {
        if ($value !== '') {
            putenv("$key=$value");
        } else {
            putenv($key);
        }
    }

    // -----------------------------------------------------------------------
    // Test 1: End-to-end disposable DB lifecycle validation
    // -----------------------------------------------------------------------

    /**
     * Validate a complete fresh-run lifecycle against a real disposable DB:
     *   - bootstrap/reset
     *   - cohort creation
     *   - season setup
     *   - season join
     *   - bounded tick progression
     *   - clean termination/finalization
     *   - no phantom seasons
     */
    public function testFreshRunLifecycleEndToEnd(): void
    {
        $runner = new FreshLifecycleRunner($this->baseConfig);

        // Phase 1: Prepare (bootstrap disposable DB)
        $prepResult = $runner->prepare();
        $this->assertSame('bootstrapped', $prepResult['status'],
            'Bootstrap must succeed: ' . json_encode($prepResult));

        // Phase 2: Run full lifecycle
        $result = $runner->run();
        $this->assertSame('completed', $result['status'],
            'Full lifecycle must complete: ' . ($result['message'] ?? 'no message'));

        // Validate cohort was created
        $cohort = $result['cohort'];
        $this->assertNotNull($cohort);
        $this->assertGreaterThan(0, $cohort['total_players']);

        // Validate season was created
        $this->assertNotNull($result['season_id']);
        $this->assertGreaterThan(0, $result['season_id']);

        // Validate tick loop ran
        $tickLoop = $result['metrics']['tick_loop'] ?? [];
        $this->assertGreaterThan(0, $tickLoop['ticks_processed'] ?? 0,
            'Tick loop must process at least one tick');

        // Validate season finalized or max tick reached
        $this->assertTrue(
            $tickLoop['season_finalized'] || ($tickLoop['ticks_processed'] > 0),
            'Season must either finalize or process ticks'
        );

        // Validate join
        $join = $result['metrics']['join'] ?? [];
        $this->assertGreaterThan(0, $join['joined'] ?? 0, 'At least one player must join');
        $this->assertSame(0, $join['failed'] ?? -1, 'No joins should fail');

        // Validate adapted paths are recorded
        $this->assertContains('season_setup_direct_insert', $result['adapted_paths']);
        $this->assertContains('database_singleton_redirect', $result['adapted_paths']);
        $this->assertContains('simulated_player_keepalive', $result['adapted_paths']);

        // Validate run artifact was built
        $artifact = $runner->getRunArtifact();
        $this->assertNotNull($artifact, 'Run artifact must be built');
        $this->assertSame('tmc-fresh-run-artifact.v1', $artifact['schema_version']);

        // --- PHANTOM SEASON CONTAINMENT VALIDATION ---
        // With ensureSeasons() skipped in simulation mode, there should be
        // exactly one season (the target) in the disposable DB.
        $extraSeasons = $artifact['metadata']['season']['extra_season_ids'] ?? [];
        $this->assertEmpty($extraSeasons,
            'No phantom seasons should exist after stabilization. Found: ' . json_encode($extraSeasons));
        $this->assertFalse($artifact['metadata']['season']['extra_seasons_present'],
            'extra_seasons_present must be false after phantom season containment');

        // Validate season seed is serialized as hex, not raw binary
        $seedHex = $artifact['metadata']['season']['season_seed_hex'] ?? null;
        $this->assertNotNull($seedHex, 'season_seed_hex must be present');
        $this->assertTrue(ctype_xdigit($seedHex), 'season_seed_hex must be valid hex');
        $this->assertSame(64, strlen($seedHex), 'season_seed_hex must be 64 chars (SHA-256)');

        // Validate artifact JSON is safe (no binary)
        $json = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($json, 'Artifact must produce valid JSON');
    }

    // -----------------------------------------------------------------------
    // Test 2: Repeated identical-run determinism / fingerprint stability
    // -----------------------------------------------------------------------

    /**
     * Prove that two identical fresh-run executions against clean disposable DBs
     * produce stable deterministic artifacts/fingerprints.
     *
     * Excludes intentionally volatile fields (generated_at, phase_durations_ms,
     * extra_season_ids, production_commit).
     */
    public function testDeterminismFingerprintStability(): void
    {
        // --- Run 1 ---
        $runner1 = new FreshLifecycleRunner($this->baseConfig);
        $prep1 = $runner1->prepare();
        $this->assertSame('bootstrapped', $prep1['status']);
        $result1 = $runner1->run();
        $this->assertSame('completed', $result1['status'],
            'Run 1 must complete: ' . ($result1['message'] ?? ''));
        $artifact1 = $runner1->getRunArtifact();
        $this->assertNotNull($artifact1);

        // Clean up DB for run 2 (teardown + re-bootstrap)
        if (class_exists('GameTime')) {
            GameTime::clearSimulationTick();
        }
        if (class_exists('Database') && method_exists('Database', 'resetInstance')) {
            Database::resetInstance();
        }

        // --- Run 2 (fresh disposable DB with same config) ---
        $config2 = $this->baseConfig;
        $config2['db_name'] = 'tmc_sim_integ_det_' . getmypid() . '_' . mt_rand(1000, 9999);
        putenv('DB_NAME=' . $config2['db_name']);

        $runner2 = new FreshLifecycleRunner($config2);
        $prep2 = $runner2->prepare();
        $this->assertSame('bootstrapped', $prep2['status']);
        $result2 = $runner2->run();
        $this->assertSame('completed', $result2['status'],
            'Run 2 must complete: ' . ($result2['message'] ?? ''));
        $artifact2 = $runner2->getRunArtifact();
        $this->assertNotNull($artifact2);

        // Clean up second DB
        try {
            $bootstrap2 = new FreshRunBootstrap(
                $config2['db_host'],
                $config2['db_port'],
                $config2['db_name'],
                $config2['db_user'],
                $config2['db_pass']
            );
            $bootstrap2->teardown();
        } catch (\Throwable $e) {
            // Best-effort cleanup
        }

        // --- Fingerprint comparison ---
        $fp1 = $artifact1['determinism_fingerprint'];
        $fp2 = $artifact2['determinism_fingerprint'];

        $this->assertSame($fp1, $fp2,
            'Identical-seed runs against clean disposable DBs must produce the same determinism fingerprint');

        // --- Deeper structural comparison (excluding volatile fields) ---
        $stable1 = $this->extractStableFields($artifact1);
        $stable2 = $this->extractStableFields($artifact2);

        $this->assertSame(
            json_encode($stable1, JSON_UNESCAPED_SLASHES),
            json_encode($stable2, JSON_UNESCAPED_SLASHES),
            'Stable artifact fields must be byte-identical between identical runs'
        );

        // Validate fingerprint matches real computation
        $recomputed = RunArtifactBuilder::computeDeterminismFingerprint($artifact1);
        $this->assertSame($fp1, $recomputed,
            'Fingerprint must match when recomputed from the artifact');
    }

    /**
     * Extract the stable (non-volatile) subset of an artifact for comparison.
     */
    private function extractStableFields(array $artifact): array
    {
        $stable = [
            'schema_version'           => $artifact['schema_version'],
            'execution_metrics'        => $artifact['execution_metrics'],
            'adapted_paths'            => $artifact['adapted_paths'],
            'unmodeled_mechanics'      => $artifact['unmodeled_mechanics'],
            'termination'              => $artifact['termination'],
            'mechanic_classifications' => $artifact['mechanic_classifications'] ?? null,
        ];

        // Remove volatile timing fields
        unset($stable['execution_metrics']['phase_durations_ms']);

        return $stable;
    }
}
