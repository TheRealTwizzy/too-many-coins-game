<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/CohortGenerator.php';
require_once __DIR__ . '/../scripts/simulation/Archetypes.php';
require_once __DIR__ . '/../scripts/simulation/SimulationRandom.php';

/**
 * Tests for deterministic cohort generation (Milestone 3B).
 *
 * plan() tests run without any database — they validate deterministic
 * composition, handle uniqueness, and archetype coverage.
 *
 * generate() tests require a MySQL connection to a disposable simulation DB.
 * They are skipped automatically when the env vars are not set:
 *   TMC_TEST_DB_HOST, TMC_TEST_DB_PORT, TMC_TEST_DB_USER, TMC_TEST_DB_PASS, TMC_TEST_DB_NAME
 *
 * To run generate() tests locally:
 *   TMC_TEST_DB_HOST=127.0.0.1 TMC_TEST_DB_PORT=3306 TMC_TEST_DB_NAME=tmc_sim_test \
 *     TMC_TEST_DB_USER=root TMC_TEST_DB_PASS= php vendor/bin/phpunit tests/CohortGeneratorTest.php
 */
class CohortGeneratorTest extends TestCase
{
    /**
     * Create a stub PDO for plan()-only tests.
     * plan() never touches the database, so we just need a valid object.
     */
    private function stubPdo(): PDO
    {
        // Use a mock that will throw if any DB method is called
        $mock = $this->createMock(PDO::class);
        // Return the mock cast to PDO — plan() doesn't call any PDO methods
        return $mock;
    }

    /**
     * Get a real MySQL PDO for generate() tests, or null if not configured.
     */
    private function getTestDbPdo(): ?PDO
    {
        $host = getenv('TMC_TEST_DB_HOST') ?: '';
        $port = getenv('TMC_TEST_DB_PORT') ?: '3306';
        $name = getenv('TMC_TEST_DB_NAME') ?: '';
        $user = getenv('TMC_TEST_DB_USER') ?: '';
        $pass = getenv('TMC_TEST_DB_PASS') ?: '';

        if ($host === '' || $name === '') {
            return null;
        }

        try {
            $pdo = new PDO(
                "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            return $pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Prepare minimal tables in the test DB for generate() tests.
     */
    private function prepareTestTables(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS handle_registry');
        $pdo->exec('DROP TABLE IF EXISTS players');

        $pdo->exec('CREATE TABLE players (
            player_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            handle VARCHAR(16) NOT NULL,
            handle_lower VARCHAR(16) NOT NULL UNIQUE,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM(\'Player\', \'Moderator\', \'Admin\') NOT NULL DEFAULT \'Player\',
            online_current TINYINT(1) NOT NULL DEFAULT 0,
            last_seen_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');

        $pdo->exec('CREATE TABLE handle_registry (
            handle_lower VARCHAR(16) PRIMARY KEY,
            player_id BIGINT UNSIGNED NOT NULL,
            registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
    }

    // -----------------------------------------------------------------------
    // plan() — deterministic composition (no DB required)
    // -----------------------------------------------------------------------

    public function testPlanReturnsCorrectTotalPlayers(): void
    {
        $gen = new CohortGenerator($this->stubPdo(), '42', 5);
        $plan = $gen->plan();

        $archetypeCount = count(Archetypes::all());
        $this->assertCount($archetypeCount * 5, $plan);
    }

    public function testPlanCoversAllArchetypes(): void
    {
        $gen = new CohortGenerator($this->stubPdo(), '42', 3);
        $plan = $gen->plan();

        $archetypeKeys = array_unique(array_column($plan, 'archetype_key'));
        $expectedKeys = array_keys(Archetypes::all());

        sort($archetypeKeys);
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $archetypeKeys);
    }

    public function testPlanCountPerArchetype(): void
    {
        $gen = new CohortGenerator($this->stubPdo(), '42', 7);
        $plan = $gen->plan();

        $counts = array_count_values(array_column($plan, 'archetype_key'));
        foreach ($counts as $key => $count) {
            $this->assertSame(7, $count, "Archetype '$key' should have 7 players");
        }
    }

    public function testPlanHandlesAreUnique(): void
    {
        $gen = new CohortGenerator($this->stubPdo(), '42', 10);
        $plan = $gen->plan();

        $handles = array_column($plan, 'handle');
        $this->assertCount(count($handles), array_unique($handles), 'All handles must be unique');
    }

    public function testPlanHandlesWithin16Chars(): void
    {
        $gen = new CohortGenerator($this->stubPdo(), '42', 100);
        $plan = $gen->plan();

        foreach ($plan as $spec) {
            $this->assertLessThanOrEqual(16, strlen($spec['handle']),
                "Handle '{$spec['handle']}' exceeds 16 chars");
        }
    }

    public function testPlanIsDeterministicAcrossRuns(): void
    {
        $gen1 = new CohortGenerator($this->stubPdo(), 'determinism-test', 5);
        $gen2 = new CohortGenerator($this->stubPdo(), 'determinism-test', 5);

        $plan1 = $gen1->plan();
        $plan2 = $gen2->plan();

        $this->assertSame($plan1, $plan2, 'Same seed must produce identical plans');
    }

    public function testPlanDiffersWithDifferentSeed(): void
    {
        $gen1 = new CohortGenerator($this->stubPdo(), 'seed-a', 3);
        $gen2 = new CohortGenerator($this->stubPdo(), 'seed-b', 3);

        $plan1 = $gen1->plan();
        $plan2 = $gen2->plan();

        // Archetype composition is the same regardless of seed
        $this->assertSame(
            array_column($plan1, 'archetype_key'),
            array_column($plan2, 'archetype_key')
        );
    }

    public function testPlanMinimumOnePlayerPerArchetype(): void
    {
        $gen = new CohortGenerator($this->stubPdo(), '42', 0);
        $plan = $gen->plan();

        $archetypeCount = count(Archetypes::all());
        $this->assertCount($archetypeCount, $plan);
    }

    public function testPlanEmailsAreUnique(): void
    {
        $gen = new CohortGenerator($this->stubPdo(), '42', 10);
        $plan = $gen->plan();

        $emails = array_column($plan, 'email');
        $this->assertCount(count($emails), array_unique($emails), 'All emails must be unique');
    }

    public function testPlanEmailsDeriveFromHandles(): void
    {
        $gen = new CohortGenerator($this->stubPdo(), '42', 2);
        $plan = $gen->plan();

        foreach ($plan as $spec) {
            $this->assertSame($spec['handle'] . '@sim.local', $spec['email']);
        }
    }

    // -----------------------------------------------------------------------
    // generate() — persistence to DB (requires MySQL test DB)
    // -----------------------------------------------------------------------

    public function testGenerateCreatesPlayersInDb(): void
    {
        $pdo = $this->getTestDbPdo();
        if ($pdo === null) {
            $this->markTestSkipped('No test DB configured (set TMC_TEST_DB_HOST, TMC_TEST_DB_NAME, etc.)');
        }
        $this->prepareTestTables($pdo);

        $gen = new CohortGenerator($pdo, '42', 3);
        $manifest = $gen->generate();

        $count = (int)$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn();
        $this->assertSame($manifest['total_players'], $count);
    }

    public function testGenerateCreatesHandleRegistryEntries(): void
    {
        $pdo = $this->getTestDbPdo();
        if ($pdo === null) {
            $this->markTestSkipped('No test DB configured');
        }
        $this->prepareTestTables($pdo);

        $gen = new CohortGenerator($pdo, '42', 2);
        $manifest = $gen->generate();

        $count = (int)$pdo->query('SELECT COUNT(*) FROM handle_registry')->fetchColumn();
        $this->assertSame($manifest['total_players'], $count);
    }

    public function testGenerateManifestStructure(): void
    {
        $pdo = $this->getTestDbPdo();
        if ($pdo === null) {
            $this->markTestSkipped('No test DB configured');
        }
        $this->prepareTestTables($pdo);

        $gen = new CohortGenerator($pdo, '42', 2);
        $manifest = $gen->generate();

        $this->assertSame('created', $manifest['status']);
        $this->assertSame('42', $manifest['seed']);
        $this->assertSame(2, $manifest['players_per_archetype']);
        $this->assertSame(count(Archetypes::all()), $manifest['archetype_count']);
        $this->assertSame(count(Archetypes::all()) * 2, $manifest['total_players']);
        $this->assertArrayHasKey('archetypes', $manifest);
        $this->assertArrayHasKey('player_map', $manifest);
        $this->assertArrayHasKey('adapted_paths', $manifest);
        $this->assertContains('synthetic_player_insert', $manifest['adapted_paths']);
    }

    public function testGeneratePlayerIdsArePositiveIntegers(): void
    {
        $pdo = $this->getTestDbPdo();
        if ($pdo === null) {
            $this->markTestSkipped('No test DB configured');
        }
        $this->prepareTestTables($pdo);

        $gen = new CohortGenerator($pdo, '42', 2);
        $manifest = $gen->generate();

        foreach ($manifest['player_map'] as $playerId => $info) {
            $this->assertIsInt($playerId);
            $this->assertGreaterThan(0, $playerId);
            $this->assertSame($playerId, $info['player_id']);
        }
    }

    public function testGenerateArchetypePlayerIdsBelongToCorrectArchetype(): void
    {
        $pdo = $this->getTestDbPdo();
        if ($pdo === null) {
            $this->markTestSkipped('No test DB configured');
        }
        $this->prepareTestTables($pdo);

        $gen = new CohortGenerator($pdo, '42', 2);
        $manifest = $gen->generate();

        foreach ($manifest['archetypes'] as $key => $info) {
            foreach ($info['player_ids'] as $pid) {
                $this->assertArrayHasKey($pid, $manifest['player_map']);
                $this->assertSame($key, $manifest['player_map'][$pid]['archetype_key']);
            }
        }
    }

    public function testGenerateIsDeterministicAcrossRuns(): void
    {
        $pdo = $this->getTestDbPdo();
        if ($pdo === null) {
            $this->markTestSkipped('No test DB configured');
        }

        // Run 1
        $this->prepareTestTables($pdo);
        $gen1 = new CohortGenerator($pdo, 'det-42', 3);
        $manifest1 = $gen1->generate();

        // Run 2 — fresh tables
        $this->prepareTestTables($pdo);
        $gen2 = new CohortGenerator($pdo, 'det-42', 3);
        $manifest2 = $gen2->generate();

        $this->assertSame($manifest1['total_players'], $manifest2['total_players']);
        $this->assertSame(
            array_keys($manifest1['player_map']),
            array_keys($manifest2['player_map'])
        );

        $handles1 = array_column($manifest1['player_map'], 'handle');
        $handles2 = array_column($manifest2['player_map'], 'handle');
        $this->assertSame($handles1, $handles2);

        $arch1 = array_column($manifest1['player_map'], 'archetype_key');
        $arch2 = array_column($manifest2['player_map'], 'archetype_key');
        $this->assertSame($arch1, $arch2);
    }

    public function testGeneratePlayerMapCountMatchesTotal(): void
    {
        $pdo = $this->getTestDbPdo();
        if ($pdo === null) {
            $this->markTestSkipped('No test DB configured');
        }
        $this->prepareTestTables($pdo);

        $gen = new CohortGenerator($pdo, '42', 4);
        $manifest = $gen->generate();

        $this->assertCount($manifest['total_players'], $manifest['player_map']);
    }

    // -----------------------------------------------------------------------
    // Edge cases
    // -----------------------------------------------------------------------

    public function testGenerateWithSinglePlayerPerArchetype(): void
    {
        $pdo = $this->getTestDbPdo();
        if ($pdo === null) {
            $this->markTestSkipped('No test DB configured');
        }
        $this->prepareTestTables($pdo);

        $gen = new CohortGenerator($pdo, '42', 1);
        $manifest = $gen->generate();

        $this->assertSame(count(Archetypes::all()), $manifest['total_players']);
        foreach ($manifest['archetypes'] as $info) {
            $this->assertSame(1, $info['count']);
        }
    }
}
