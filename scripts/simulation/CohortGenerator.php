<?php
/**
 * Deterministic cohort generation for fresh-run simulation.
 *
 * Milestone 3B: Creates a bounded set of synthetic players from archetype
 * definitions, persists them to the disposable DB, and returns a manifest
 * describing the cohort composition.
 *
 * Design:
 *   - Uses SimulationRandom for all non-trivial randomness so cohorts are
 *     reproducible under a fixed seed.
 *   - Reuses Archetypes::all() for archetype definitions; does not duplicate
 *     or invent new archetype data.
 *   - Inserts players directly via PDO against the disposable DB schema
 *     (players + handle_registry tables). Season join is NOT performed here;
 *     that is deferred to Milestone 3C.
 *   - Each player gets a deterministic handle, a placeholder email, and a
 *     dummy password hash. These are simulation-only records.
 */

require_once __DIR__ . '/SimulationRandom.php';
require_once __DIR__ . '/Archetypes.php';

class CohortGenerator
{
    private PDO $pdo;
    private string $seed;
    private int $playersPerArchetype;

    /**
     * @param PDO    $pdo                  Connection to the disposable simulation DB.
     * @param string $seed                 Deterministic seed string.
     * @param int    $playersPerArchetype  Number of players to create per archetype.
     */
    public function __construct(PDO $pdo, string $seed, int $playersPerArchetype)
    {
        $this->pdo = $pdo;
        $this->seed = $seed;
        $this->playersPerArchetype = max(1, $playersPerArchetype);
    }

    /**
     * Build a deterministic cohort composition plan without writing to the DB.
     *
     * Returns an ordered list of player specs: archetype key, index, handle.
     * Useful for inspecting/testing composition separately from persistence.
     *
     * @return array<int, array{archetype_key: string, index: int, handle: string, email: string}>
     */
    public function plan(): array
    {
        $archetypes = Archetypes::all();
        $plan = [];
        $ordinal = 0;

        foreach ($archetypes as $key => $archetype) {
            for ($i = 0; $i < $this->playersPerArchetype; $i++) {
                $ordinal++;
                $handle = $this->deterministicHandle($key, $i, $ordinal);
                $plan[] = [
                    'archetype_key' => $key,
                    'index'         => $i,
                    'handle'        => $handle,
                    'email'         => $handle . '@sim.local',
                ];
            }
        }

        return $plan;
    }

    /**
     * Generate and persist synthetic players to the disposable DB.
     *
     * Returns a cohort manifest with composition summary and player IDs.
     *
     * @return array{
     *   status: string,
     *   seed: string,
     *   players_per_archetype: int,
     *   archetype_count: int,
     *   total_players: int,
     *   archetypes: array<string, array{label: string, count: int, player_ids: int[]}>,
     *   player_map: array<int, array{player_id: int, archetype_key: string, handle: string}>,
     *   adapted_paths: string[]
     * }
     */
    public function generate(): array
    {
        $plan = $this->plan();
        $archetypes = Archetypes::all();

        // Dummy password hash — simulation players never authenticate.
        // Use a fixed deterministic string so password_hash column is identical
        // across runs. bcrypt's random salt makes password_hash() nondeterministic,
        // so we use a pre-computed constant instead.
        $dummyHash = '$2y$04$SimPlaceholderHashForTMC.SeedInvariantDoNotAuthenticate0';

        $insertPlayer = $this->pdo->prepare(
            'INSERT INTO players (handle, handle_lower, email, password_hash, role, online_current, last_seen_at, created_at) '
            . 'VALUES (:handle, :handle_lower, :email, :hash, :role, 0, :ts, :ts)'
        );

        $insertRegistry = $this->pdo->prepare(
            'INSERT INTO handle_registry (handle_lower, player_id, registered_at) VALUES (:handle_lower, :player_id, :ts)'
        );

        $manifest = [
            'status'                => 'created',
            'seed'                  => $this->seed,
            'players_per_archetype' => $this->playersPerArchetype,
            'archetype_count'       => count($archetypes),
            'total_players'         => count($plan),
            'archetypes'            => [],
            'player_map'            => [],
            'adapted_paths'         => ['synthetic_player_insert'],
        ];

        // Initialize archetype buckets
        foreach ($archetypes as $key => $archetype) {
            $manifest['archetypes'][$key] = [
                'label'      => $archetype['label'],
                'count'      => 0,
                'player_ids' => [],
            ];
        }

        // Deterministic creation timestamp — avoids wall-clock nondeterminism.
        // Uses a fixed epoch so player rows are byte-identical across runs.
        $deterministicTs = '2026-01-01 00:00:00';

        $this->pdo->beginTransaction();
        try {
            foreach ($plan as $spec) {
                $insertPlayer->execute([
                    ':handle'       => $spec['handle'],
                    ':handle_lower' => strtolower($spec['handle']),
                    ':email'        => $spec['email'],
                    ':hash'         => $dummyHash,
                    ':role'         => 'Player',
                    ':ts'           => $deterministicTs,
                ]);

                $playerId = (int)$this->pdo->lastInsertId();

                $insertRegistry->execute([
                    ':handle_lower' => strtolower($spec['handle']),
                    ':player_id'    => $playerId,
                    ':ts'           => $deterministicTs,
                ]);

                $manifest['archetypes'][$spec['archetype_key']]['count']++;
                $manifest['archetypes'][$spec['archetype_key']]['player_ids'][] = $playerId;

                $manifest['player_map'][$playerId] = [
                    'player_id'     => $playerId,
                    'archetype_key' => $spec['archetype_key'],
                    'handle'        => $spec['handle'],
                ];
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Cohort generation failed: ' . $e->getMessage(), 0, $e);
        }

        return $manifest;
    }

    /**
     * Build a deterministic handle for a synthetic player.
     *
     * Format: sim_{archetype_prefix}_{ordinal}
     * Capped at 16 chars (handle column constraint).
     */
    private function deterministicHandle(string $archetypeKey, int $index, int $ordinal): string
    {
        // Abbreviate archetype keys to fit within 16-char handle limit
        $prefixMap = [
            'casual'                => 'cas',
            'regular'               => 'reg',
            'hardcore'              => 'hrd',
            'hoarder'               => 'hoa',
            'early_locker'          => 'elc',
            'late_deployer'         => 'ltd',
            'boost_focused'         => 'bst',
            'star_focused'          => 'str',
            'aggressive_sigil_user' => 'agg',
            'mostly_idle'           => 'idl',
        ];

        $prefix = $prefixMap[$archetypeKey] ?? substr($archetypeKey, 0, 3);
        $handle = sprintf('s_%s_%d', $prefix, $ordinal);

        // Ensure 16-char max
        if (strlen($handle) > 16) {
            $handle = substr($handle, 0, 16);
        }

        return $handle;
    }
}
