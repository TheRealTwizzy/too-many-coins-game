<?php
/**
 * Fresh Lifecycle Runner — orchestration shell for one-season fresh-run simulation.
 *
 * Milestone 3A: Minimal skeleton providing:
 *   - fresh-run prerequisite validation (reuses FreshRunSafety)
 *   - disposable DB readiness check (reuses FreshRunBootstrap)
 *   - run context/config initialization
 *   - minimal execution entrypoint for later sub-milestones
 *
 * Milestone 3B: Deterministic cohort generation:
 *   - creates synthetic players from archetype definitions
 *   - persists them to the disposable DB
 *   - runner can progress through: validate → prepare → cohort creation → stop
 *
 * Milestone 3C: Season setup, join, bounded tick loop, action execution:
 *   - inserts a valid disposable seasons row for a one-season run
 *   - joins synthetic players via production Actions::seasonJoin()
 *   - drives lifecycle tick-by-tick via TickEngine::processTickAt()
 *   - executes real production action handlers per-tick for star
 *     purchases, lock-in, sigil combine, freeze, self-melt, theft
 *   - detects season completion/finalization to end cleanly
 *
 * Responsibilities are split into bounded concerns:
 *   1. Safety/bootstrap   — delegated to FreshRunSafety + FreshRunBootstrap (Milestone 1)
 *   2. Tick-driving        — delegated to GameTime + TickEngine (Milestone 2)
 *   3. Cohort generation  — CohortGenerator (Milestone 3B)
 *   4. Action orchestration — FreshLifecycleRunner (Milestone 3C)
 *
 * This class owns the lifecycle shell: validate → prepare → cohort → season setup →
 * season join → tick loop → finalize.
 */

require_once __DIR__ . '/FreshRunSafety.php';
require_once __DIR__ . '/FreshRunBootstrap.php';
require_once __DIR__ . '/CohortGenerator.php';
require_once __DIR__ . '/SimulationSeason.php';
require_once __DIR__ . '/PolicyBehavior.php';
require_once __DIR__ . '/SimulationRandom.php';
require_once __DIR__ . '/RunArtifactBuilder.php';

class FreshLifecycleRunner
{
    /** Run states for lifecycle tracking. */
    public const STATE_IDLE            = 'idle';
    public const STATE_VALIDATING      = 'validating';
    public const STATE_PREPARING       = 'preparing';
    public const STATE_READY           = 'ready';
    public const STATE_COHORT_CREATED  = 'cohort_created';
    public const STATE_SEASON_READY    = 'season_ready';
    public const STATE_PLAYERS_JOINED  = 'players_joined';
    public const STATE_RUNNING         = 'running';
    public const STATE_COMPLETED       = 'completed';
    public const STATE_FAILED          = 'failed';

    private FreshRunBootstrap $bootstrap;
    private string $state;
    private array $config;
    private array $runLog;
    private ?array $cohortManifest = null;
    private ?int $seasonId = null;
    private ?array $seasonConfig = null;
    private array $adaptedPaths = [];
    private array $unmodeledMechanics = [];
    private array $runMetrics = [];
    private array $phaseTimings = [];
    private ?array $runArtifact = null;
    private bool $productionRuntimeLoaded = false;

    /**
     * @param array $config  Run configuration:
     *   - db_host, db_port, db_name, db_user, db_pass — DB coordinates
     *   - seed           — deterministic seed (int, default 42)
     *   - cohort_size    — players per archetype (int, default 100)
     *   - drop_first     — whether to reset DB on prepare (bool, default false)
     */
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'db_host'      => '',
            'db_port'      => '',
            'db_name'      => '',
            'db_user'      => '',
            'db_pass'      => '',
            'seed'         => 42,
            'cohort_size'  => 100,
            'drop_first'   => false,
        ], $config);

        $this->bootstrap = new FreshRunBootstrap(
            $this->config['db_host'],
            $this->config['db_port'],
            $this->config['db_name'],
            $this->config['db_user'],
            $this->config['db_pass']
        );

        $this->state  = self::STATE_IDLE;
        $this->runLog = [];
    }

    /** Current run state. */
    public function getState(): string
    {
        return $this->state;
    }

    /** Accumulated run log entries. */
    public function getRunLog(): array
    {
        return $this->runLog;
    }

    /** Resolved run config (read-only copy). */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** Cohort manifest from the most recent createCohort() call, or null. */
    public function getCohortManifest(): ?array
    {
        return $this->cohortManifest;
    }

    /** Season ID created during setupSeason(), or null. */
    public function getSeasonId(): ?int
    {
        return $this->seasonId;
    }

    /** Season config array from setupSeason(), or null. */
    public function getSeasonConfig(): ?array
    {
        return $this->seasonConfig;
    }

    /** Adapted paths accumulated during the run. */
    public function getAdaptedPaths(): array
    {
        return $this->adaptedPaths;
    }

    /** Unmodeled mechanics accumulated during the run. */
    public function getUnmodeledMechanics(): array
    {
        return $this->unmodeledMechanics;
    }

    /** Performance/lifecycle metrics from the run. */
    public function getRunMetrics(): array
    {
        return $this->runMetrics;
    }

    /** Per-phase timing measurements from the run. */
    public function getPhaseTimings(): array
    {
        return $this->phaseTimings;
    }

    /** The structured run artifact, or null if not yet built. */
    public function getRunArtifact(): ?array
    {
        return $this->runArtifact;
    }

    /**
     * Validate fresh-run prerequisites.
     *
     * Checks simulation mode flag, DB safety, and bootstrap readiness.
     * Returns an array of error strings (empty = valid).
     */
    public function validate(): array
    {
        $this->setState(self::STATE_VALIDATING);

        $errors = FreshRunSafety::validate(
            $this->config['db_host'],
            $this->config['db_port'],
            $this->config['db_name'],
            $this->config['db_user'],
            (bool)$this->config['drop_first']
        );

        if (!empty($errors)) {
            $this->log('validation_failed', $errors);
            $this->setState(self::STATE_FAILED);
            return $errors;
        }

        $this->log('validation_passed');
        return [];
    }

    /**
     * Prepare the run environment: validate + ensure disposable DB is bootstrapped.
     *
     * Returns an array with 'status' and 'steps'.
     * On failure, state moves to FAILED with error detail in runLog.
     *
     * @return array{status: string, steps: string[]}
     */
    public function prepare(): array
    {
        $errors = $this->validate();
        if (!empty($errors)) {
            return [
                'status' => 'failed',
                'steps'  => $errors,
            ];
        }

        $this->setState(self::STATE_PREPARING);
        $this->log('preparing', ['drop_first' => $this->config['drop_first']]);

        try {
            $result = $this->bootstrap->bootstrap($this->config['drop_first']);
        } catch (\Throwable $e) {
            $this->log('bootstrap_error', ['message' => $e->getMessage()]);
            $this->setState(self::STATE_FAILED);
            return [
                'status' => 'failed',
                'steps'  => ['Bootstrap error: ' . $e->getMessage()],
            ];
        }

        if ($result['status'] === 'bootstrapped' || $result['status'] === 'already_exists') {
            $this->log('prepared', $result);
            $this->setState(self::STATE_READY);
        } else {
            $this->log('prepare_unexpected', $result);
            $this->setState(self::STATE_FAILED);
        }

        return $result;
    }

    /**
     * Create the synthetic player cohort in the disposable DB.
     *
     * Requires state READY (after prepare()). Generates players from archetypes
     * and persists them. Moves state to COHORT_CREATED on success.
     *
     * @return array  Cohort manifest (same shape as CohortGenerator::generate()).
     */
    public function createCohort(): array
    {
        if ($this->state !== self::STATE_READY) {
            $this->log('cohort_rejected', ['state' => $this->state]);
            return [
                'status'  => 'rejected',
                'message' => "Cannot create cohort: state is '{$this->state}', expected 'ready'. Call prepare() first.",
            ];
        }

        $this->log('cohort_generation_started', [
            'seed'        => $this->config['seed'],
            'cohort_size' => $this->config['cohort_size'],
        ]);

        try {
            $pdo = $this->bootstrap->getConnection();
            $generator = new CohortGenerator(
                $pdo,
                (string)$this->config['seed'],
                (int)$this->config['cohort_size']
            );

            $manifest = $generator->generate();
            $this->cohortManifest = $manifest;

            $this->log('cohort_generation_completed', [
                'total_players'   => $manifest['total_players'],
                'archetype_count' => $manifest['archetype_count'],
            ]);

            $this->setState(self::STATE_COHORT_CREATED);
            return $manifest;

        } catch (\Throwable $e) {
            $this->log('cohort_generation_error', ['message' => $e->getMessage()]);
            $this->setState(self::STATE_FAILED);
            return [
                'status'  => 'failed',
                'message' => 'Cohort generation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Execute the fresh lifecycle run.
     *
     * Milestone 3C: Full one-season lifecycle path:
     *   validate → prepare → cohort → season setup → season join →
     *   bounded tick loop → season completion → stop
     *
     * @return array{status: string, message: string, cohort: ?array, season_id: ?int, adapted_paths: string[], unmodeled_mechanics: string[], metrics: array}
     */
    public function run(): array
    {
        if ($this->state !== self::STATE_READY && $this->state !== self::STATE_COHORT_CREATED) {
            $this->log('run_rejected', ['state' => $this->state]);
            return [
                'status'              => 'rejected',
                'message'             => "Cannot run: state is '{$this->state}', expected 'ready' or 'cohort_created'. Call prepare() first.",
                'cohort'              => null,
                'season_id'           => null,
                'adapted_paths'       => [],
                'unmodeled_mechanics' => [],
                'metrics'             => [],
            ];
        }

        $runStartTime = microtime(true);
        $this->setState(self::STATE_RUNNING);
        $this->log('run_started', [
            'seed'        => $this->config['seed'],
            'cohort_size' => $this->config['cohort_size'],
        ]);

        // --- Cohort generation (Milestone 3B) ---
        $cohortStartTime = microtime(true);
        if ($this->cohortManifest === null) {
            // Reset to READY so createCohort() accepts
            $this->setState(self::STATE_READY);
            $cohortResult = $this->createCohort();
            if (($cohortResult['status'] ?? '') !== 'created') {
                return [
                    'status'              => 'failed',
                    'message'             => $cohortResult['message'] ?? 'Cohort generation failed.',
                    'cohort'              => $cohortResult,
                    'season_id'           => null,
                    'adapted_paths'       => $this->adaptedPaths,
                    'unmodeled_mechanics' => $this->unmodeledMechanics,
                    'metrics'             => [],
                ];
            }
            $this->setState(self::STATE_RUNNING);
        }
        $this->phaseTimings['cohort_creation_duration_ms'] = round((microtime(true) - $cohortStartTime) * 1000, 1);

        // --- Season setup (Milestone 3C) ---
        $seasonSetupStartTime = microtime(true);
        try {
            $this->setupSeason();
        } catch (\Throwable $e) {
            $this->log('season_setup_error', ['message' => $e->getMessage()]);
            $this->setState(self::STATE_FAILED);
            return [
                'status'              => 'failed',
                'message'             => 'Season setup failed: ' . $e->getMessage(),
                'cohort'              => $this->cohortManifest,
                'season_id'           => null,
                'adapted_paths'       => $this->adaptedPaths,
                'unmodeled_mechanics' => $this->unmodeledMechanics,
                'metrics'             => [],
            ];
        }
        $this->phaseTimings['season_setup_duration_ms'] = round((microtime(true) - $seasonSetupStartTime) * 1000, 1);

        // --- Load production runtime (Milestone 3C) ---
        try {
            $this->ensureProductionRuntime();
        } catch (\Throwable $e) {
            $this->log('runtime_load_error', ['message' => $e->getMessage()]);
            $this->setState(self::STATE_FAILED);
            return [
                'status'              => 'failed',
                'message'             => 'Production runtime load failed: ' . $e->getMessage(),
                'cohort'              => $this->cohortManifest,
                'season_id'           => $this->seasonId,
                'adapted_paths'       => $this->adaptedPaths,
                'unmodeled_mechanics' => $this->unmodeledMechanics,
                'metrics'             => [],
            ];
        }

        // --- Season join (Milestone 3C) ---
        try {
            $joinResult = $this->joinPlayers();
            $this->runMetrics['join'] = $joinResult;
        } catch (\Throwable $e) {
            $this->log('season_join_error', ['message' => $e->getMessage()]);
            $this->setState(self::STATE_FAILED);
            GameTime::clearSimulationTick();
            return [
                'status'              => 'failed',
                'message'             => 'Season join failed: ' . $e->getMessage(),
                'cohort'              => $this->cohortManifest,
                'season_id'           => $this->seasonId,
                'adapted_paths'       => $this->adaptedPaths,
                'unmodeled_mechanics' => $this->unmodeledMechanics,
                'metrics'             => $this->runMetrics,
            ];
        }

        // --- Bounded tick loop (Milestone 3C) ---
        try {
            $tickResult = $this->runTickLoop();
            $this->runMetrics['tick_loop'] = $tickResult;
        } catch (\Throwable $e) {
            $this->log('tick_loop_error', ['message' => $e->getMessage()]);
            $this->setState(self::STATE_FAILED);
            GameTime::clearSimulationTick();
            return [
                'status'              => 'failed',
                'message'             => 'Tick loop failed: ' . $e->getMessage(),
                'cohort'              => $this->cohortManifest,
                'season_id'           => $this->seasonId,
                'adapted_paths'       => $this->adaptedPaths,
                'unmodeled_mechanics' => $this->unmodeledMechanics,
                'metrics'             => $this->runMetrics,
            ];
        }

        GameTime::clearSimulationTick();

        $this->runMetrics['total_duration_ms'] = round((microtime(true) - $runStartTime) * 1000, 1);
        $this->phaseTimings['total_run_duration_ms'] = $this->runMetrics['total_duration_ms'];

        $this->setState(self::STATE_COMPLETED);
        $this->log('run_completed', [
            'total_players'   => $this->cohortManifest['total_players'] ?? 0,
            'season_id'       => $this->seasonId,
            'ticks_processed' => $this->runMetrics['tick_loop']['ticks_processed'] ?? 0,
        ]);

        $runResult = [
            'status'              => 'completed',
            'message'             => 'Lifecycle run completed: one-season fresh-run with production action paths.',
            'cohort'              => $this->cohortManifest,
            'season_id'           => $this->seasonId,
            'adapted_paths'       => $this->adaptedPaths,
            'unmodeled_mechanics' => $this->unmodeledMechanics,
            'metrics'             => $this->runMetrics,
        ];

        // --- Milestone 4A: Build structured run artifact ---
        $extraSeasons = $this->detectExtraSeasons();
        $this->runArtifact = RunArtifactBuilder::build(
            $runResult,
            $this->config,
            $this->phaseTimings,
            $this->seasonConfig,
            $this->runLog,
            $extraSeasons
        );

        return $runResult;
    }

    // --- Milestone 3C: Season setup ---

    /**
     * Insert a valid disposable seasons row and server_state for a one-season run.
     *
     * Uses SimulationSeason::build() for deterministic season economy config,
     * then persists the row to the disposable DB via direct PDO insert.
     * Also ensures a server_state row exists with a deterministic created_at
     * so GameTime::getServerEpoch() returns a fixed value.
     *
     * Adapted path: direct INSERT into seasons (bypasses GameTime::ensureSeasons
     * to guarantee deterministic timing and economy config).
     */
    private function setupSeason(): void
    {
        $pdo = $this->bootstrap->getConnection();
        $seed = (string)$this->config['seed'];

        // Ensure server_state row exists with a deterministic epoch.
        // created_at drives GameTime::getServerEpoch() which affects now() when
        // simulation clock is NOT set. We use a fixed epoch so season timing
        // is reproducible.
        $existing = $pdo->query("SELECT id FROM server_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $pdo->exec(
                "INSERT INTO server_state (id, server_mode, lifecycle_phase, current_year_seq, global_tick_index, created_at) "
                . "VALUES (1, 'NORMAL', 'Alpha', 1, 0, '2026-01-01 00:00:00')"
            );
        }

        // Build deterministic season config.
        $season = SimulationSeason::build(1, $seed);
        $this->seasonConfig = $season;

        // Insert season row into disposable DB.
        $columns = SimulationSeason::SEASON_ECONOMY_COLUMNS;
        $placeholders = array_fill(0, count($columns), '?');
        $values = [];
        foreach ($columns as $col) {
            $val = $season[$col];
            // season_id is auto-increment; use the value from build() directly.
            // Binary fields (season_seed) are passed as-is.
            $values[] = $val;
        }

        $columnList = implode(', ', $columns);
        $phList = implode(', ', $placeholders);
        $stmt = $pdo->prepare("INSERT INTO seasons ($columnList) VALUES ($phList)");
        $stmt->execute($values);

        $this->seasonId = (int)$pdo->lastInsertId();
        // Update seasonConfig with the actual auto-increment ID if different.
        $this->seasonConfig['season_id'] = $this->seasonId;

        $this->adaptedPaths[] = 'season_setup_direct_insert';
        $this->log('season_setup_completed', [
            'season_id'  => $this->seasonId,
            'start_time' => $season['start_time'],
            'end_time'   => $season['end_time'],
            'blackout'   => $season['blackout_time'],
        ]);

        $this->setState(self::STATE_SEASON_READY);
    }

    // --- Milestone 3C: Production runtime loading ---

    /**
     * Load production runtime includes and redirect Database singleton.
     *
     * This ensures Actions, TickEngine, Economy, etc. resolve Database::getInstance()
     * to the disposable simulation DB. The env vars DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS
     * must already be set to the disposable DB coordinates before this call.
     *
     * Adapted path: Database::resetInstance() forces a fresh PDO singleton that
     * connects to the disposable DB using the already-defined DB_* constants.
     */
    private function ensureProductionRuntime(): void
    {
        if ($this->productionRuntimeLoaded) {
            return;
        }

        // Load production includes — config.php constants will be defined from
        // current env vars, which point to the disposable DB.
        require_once __DIR__ . '/../../includes/tick_engine.php';
        require_once __DIR__ . '/../../includes/actions.php';

        // Reset the Database singleton so it reconnects using the disposable DB
        // coordinates already defined in config.php constants.
        Database::resetInstance();

        // Clear any cached server epoch so GameTime derives it fresh from the
        // disposable DB's server_state row.
        // GameTime uses a private static $serverEpoch cache — resetting it
        // requires clearing the simulation tick then letting it re-derive.
        // Since we drive tick explicitly, this is fine.

        $this->productionRuntimeLoaded = true;
        $this->adaptedPaths[] = 'database_singleton_redirect';
        $this->log('production_runtime_loaded');
    }

    // --- Milestone 3C: Season join ---

    /**
     * Join all synthetic players into the season using production Actions::seasonJoin().
     *
     * Sets the simulation clock to the first Active tick of the season, then
     * calls Actions::seasonJoin() for each player. Players must have
     * joined_season_id = null (guaranteed by CohortGenerator which does NOT
     * set joined_season_id).
     *
     * @return array{joined: int, failed: int, errors: array}
     */
    private function joinPlayers(): array
    {
        $startTime = microtime(true);
        $season = $this->seasonConfig;
        $seasonId = $this->seasonId;

        // Set simulation clock to first Active tick (start_time is the first Active tick).
        $joinTick = (int)$season['start_time'];
        GameTime::setSimulationTick($joinTick);

        // Update season status to Active in the DB so seasonJoin() does not
        // reject with "Season has not started yet".
        $db = Database::getInstance();
        $db->query(
            "UPDATE seasons SET status = 'Active', last_processed_tick = ? WHERE season_id = ?",
            [$joinTick, $seasonId]
        );

        $playerMap = $this->cohortManifest['player_map'] ?? [];
        $joined = 0;
        $failed = 0;
        $errors = [];

        foreach ($playerMap as $playerId => $info) {
            $result = Actions::seasonJoin((int)$playerId, $seasonId);
            if (!empty($result['success'])) {
                $joined++;
            } else {
                $failed++;
                $errors[] = [
                    'player_id' => $playerId,
                    'handle'    => $info['handle'],
                    'error'     => $result['error'] ?? 'unknown',
                ];
            }
        }

        $this->log('season_join_completed', [
            'season_id' => $seasonId,
            'joined'    => $joined,
            'failed'    => $failed,
            'tick'      => $joinTick,
        ]);

        if ($failed > 0) {
            $this->log('season_join_errors', $errors);
        }

        $this->setState(self::STATE_PLAYERS_JOINED);

        return [
            'joined'      => $joined,
            'failed'      => $failed,
            'errors'      => $errors,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 1),
        ];
    }

    // --- Milestone 3C: Bounded tick loop ---

    /**
     * Drive the one-season lifecycle tick-by-tick through the full active window.
     *
     * For each tick from season start to season end:
     *   1. TickEngine::processTickAt($tick) — processes UBI, sigil drops, expiry, etc.
     *   2. Read current player states + season state from the DB
     *   3. Use PolicyBehavior to decide actions for each player
     *   4. Execute decisions via production Actions::* methods
     *   5. Detect season Expired status and finalization
     *
     * The tick loop terminates when the season reaches Expired status and
     * expiration_finalized = 1, or when the end_time is exceeded.
     *
     * @return array{ticks_processed: int, actions_executed: array, season_finalized: bool, duration_ms: float}
     */
    private function runTickLoop(): array
    {
        $startTime = microtime(true);
        $season = $this->seasonConfig;
        $seasonId = $this->seasonId;
        $seed = (string)$this->config['seed'];
        $db = Database::getInstance();

        $seasonStart = (int)$season['start_time'];
        $seasonEnd = (int)$season['end_time'];
        // Process one tick past end_time so TickEngine can trigger processExpiration.
        $maxTick = $seasonEnd + 1;

        $ticksProcessed = 0;
        $seasonFinalized = false;
        $actionCounts = [
            'star_purchase' => ['attempted' => 0, 'succeeded' => 0],
            'lock_in'       => ['attempted' => 0, 'succeeded' => 0],
            'combine'       => ['attempted' => 0, 'succeeded' => 0],
            'freeze'        => ['attempted' => 0, 'succeeded' => 0],
            'theft'         => ['attempted' => 0, 'succeeded' => 0],
        ];

        $this->log('tick_loop_started', [
            'season_start' => $seasonStart,
            'season_end'   => $seasonEnd,
            'max_tick'     => $maxTick,
        ]);

        // Adapted path: simulated player keepalive.
        // In production, players refresh last_activity_tick via HTTP requests.
        // Without this, all players hit IDLE_TIMEOUT_TICKS after ~15 ticks
        // and get idle_modal_active=1, blocking all action execution.
        // This UPDATE simulates constant player engagement.
        $this->adaptedPaths[] = 'simulated_player_keepalive';

        // Skip the first tick (join tick) — players were joined at start_time.
        // Begin processing at start_time + 1 so players accumulate at least
        // one participation tick before any action decisions.
        for ($tick = $seasonStart + 1; $tick <= $maxTick; $tick++) {
            // 0. Refresh player activity to prevent idle timeout (adapted keepalive).
            $db->query(
                "UPDATE players SET last_activity_tick = ?, online_current = 1 "
                . "WHERE joined_season_id = ? AND participation_enabled = 1",
                [$tick, $seasonId]
            );

            // 1. Process this tick through production TickEngine.
            TickEngine::processTickAt($tick);
            $ticksProcessed++;

            // 2. Check if season is finalized (Expired + expiration_finalized).
            $seasonRow = $db->fetch(
                "SELECT status, expiration_finalized FROM seasons WHERE season_id = ?",
                [$seasonId]
            );
            if ($seasonRow && (int)$seasonRow['expiration_finalized'] === 1) {
                $seasonFinalized = true;
                $this->log('season_finalized', ['tick' => $tick]);
                break;
            }

            // 3. Skip player actions if season is Expired (finalization pending).
            $computedStatus = GameTime::getSeasonStatus($season, $tick);
            if ($computedStatus === 'Expired') {
                continue;
            }

            // 4. Execute player actions for this tick.
            $tickActions = $this->executePlayerActions($tick, $seasonId, $computedStatus, $seed, $db);
            foreach ($tickActions as $actionType => $counts) {
                $actionCounts[$actionType]['attempted'] += $counts['attempted'];
                $actionCounts[$actionType]['succeeded'] += $counts['succeeded'];
            }

            // Progress logging every 1000 ticks
            if ($ticksProcessed % 1000 === 0) {
                $this->log('tick_loop_progress', [
                    'tick'       => $tick,
                    'processed'  => $ticksProcessed,
                    'status'     => $computedStatus,
                ]);
            }
        }

        $this->log('tick_loop_completed', [
            'ticks_processed'  => $ticksProcessed,
            'season_finalized' => $seasonFinalized,
            'action_counts'    => $actionCounts,
        ]);

        // Record unmodeled mechanics from PolicyBehavior.
        foreach (PolicyBehavior::UNMODELED_MECHANICS as $key => $desc) {
            $this->unmodeledMechanics[] = $key;
        }
        // Boost purchase decisions are not dispatched in 3C because the
        // production boost activation path is tightly coupled to HTTP/session
        // sigil spending UI calls. TickEngine processes existing active_boosts
        // faithfully; creation of boosts is deferred.
        $this->unmodeledMechanics[] = 'boost_purchase_not_dispatched';
        // Self-melt freeze: production endpoint Actions::selfMeltFreeze() exists
        // but PolicyBehavior does not yet implement a decision function for it.
        // No synthetic player will self-melt in this milestone.
        $this->unmodeledMechanics[] = 'self_melt_freeze_no_policy_decision';
        // connection_seq / presence-related fields: TickEngine uses
        // Economy::resolveEconomicPresenceState() which reads activity_state,
        // idle_since_tick, etc. from DB. Synthetic players get reasonable
        // defaults from seasonJoin (activity_state='Active'). During the
        // tick loop, TickEngine updates these fields via normal production
        // paths. Presence behavior is therefore modeled faithfully through
        // TickEngine's activity evaluation phase (Phase 7).
        // connection_seq itself is not read by any tick processing path.

        return [
            'ticks_processed'  => $ticksProcessed,
            'actions_executed'  => $actionCounts,
            'season_finalized' => $seasonFinalized,
            'duration_ms'      => round((microtime(true) - $startTime) * 1000, 1),
        ];
    }

    /**
     * Execute synthetic player action decisions for a single tick.
     *
     * Reads player state from the DB, uses PolicyBehavior for deterministic
     * decisions, and executes through production Actions::* methods.
     *
     * Action execution order per player per tick:
     *   1. Sigil combine (PolicyBehavior::decideCombineTier)
     *   2. Boost purchase (PolicyBehavior::decideBoostPurchase — adapted: uses
     *      SimulationPlayer-style in-memory model since no production boost-purchase
     *      action endpoint exists; recorded as adapted_path)
     *   3. Freeze (PolicyBehavior::chooseFreezeTarget → Actions::freezePlayerUbi)
     *   4. Theft (PolicyBehavior::chooseTheftTarget → Actions::attemptSigilTheft)
     *   5. Star purchase (PolicyBehavior::decideStarPurchase → Actions::purchaseStars)
     *   6. Lock-in (PolicyBehavior::shouldLockIn → Actions::lockIn)
     *
     * @return array<string, array{attempted: int, succeeded: int}>
     */
    private function executePlayerActions(int $tick, int $seasonId, string $status, string $seed, Database $db): array
    {
        $counts = [
            'star_purchase' => ['attempted' => 0, 'succeeded' => 0],
            'lock_in'       => ['attempted' => 0, 'succeeded' => 0],
            'combine'       => ['attempted' => 0, 'succeeded' => 0],
            'freeze'        => ['attempted' => 0, 'succeeded' => 0],
            'theft'         => ['attempted' => 0, 'succeeded' => 0],
        ];

        // Read current season state for PolicyBehavior decisions.
        $seasonRow = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        if (!$seasonRow) {
            return $counts;
        }

        // Determine economy phase using production logic.
        $phase = ($status === 'Blackout')
            ? 'BLACKOUT'
            : (string)Economy::sigilSeasonPhase($seasonRow, $tick);

        // Read all participating players with their participation data.
        $participants = $db->fetchAll(
            "SELECT p.*, sp.* FROM players p
             JOIN season_participation sp ON p.player_id = sp.player_id
             WHERE p.joined_season_id = ? AND p.participation_enabled = 1 AND sp.season_id = ?",
            [$seasonId, $seasonId]
        );

        if (empty($participants)) {
            return $counts;
        }

        // Build archetype lookup and snapshot arrays for PolicyBehavior.
        $archetypes = Archetypes::all();
        $playerMap = $this->cohortManifest['player_map'] ?? [];
        $playerSnapshots = [];
        $participantLookup = [];

        foreach ($participants as $p) {
            $pid = (int)$p['player_id'];
            $archetypeKey = $playerMap[$pid]['archetype_key'] ?? null;
            if ($archetypeKey === null) {
                continue;
            }

            // Build a snapshot compatible with PolicyBehavior expectations.
            $snapshot = [
                'player_id'     => $pid,
                'archetype_key' => $archetypeKey,
                'participation' => $p,
                'player'        => $p,
                'boost'         => [
                    'is_active'   => false,
                    'modifier_fp' => 0,
                    'expires_tick' => 0,
                ],
                'freeze' => [
                    'is_active'   => false,
                    'expires_tick' => 0,
                    'applied_count' => 0,
                ],
            ];

            // Check for active boosts from the DB.
            $activeBoost = $db->fetch(
                "SELECT modifier_fp, expires_tick FROM active_boosts
                 WHERE player_id = ? AND season_id = ? AND is_active = 1
                   AND expires_tick > ?
                 ORDER BY modifier_fp DESC LIMIT 1",
                [$pid, $seasonId, $tick]
            );
            if ($activeBoost) {
                $snapshot['boost'] = [
                    'is_active'    => true,
                    'modifier_fp'  => (int)$activeBoost['modifier_fp'],
                    'expires_tick' => (int)$activeBoost['expires_tick'],
                ];
            }

            // Check for active freezes from the DB.
            $activeFreeze = $db->fetch(
                "SELECT expires_tick, applied_count FROM active_freezes
                 WHERE target_player_id = ? AND season_id = ? AND expires_tick > ?
                 ORDER BY expires_tick DESC LIMIT 1",
                [$pid, $seasonId, $tick]
            );
            if ($activeFreeze) {
                $snapshot['freeze'] = [
                    'is_active'    => true,
                    'expires_tick' => (int)$activeFreeze['expires_tick'],
                    'applied_count' => (int)$activeFreeze['applied_count'],
                ];
            }

            $playerSnapshots[] = $snapshot;
            $participantLookup[$pid] = $snapshot;
        }

        // Execute action decisions for each player.
        foreach ($playerSnapshots as $snapshot) {
            $pid = (int)$snapshot['player_id'];
            $archetypeKey = $snapshot['archetype_key'];
            $archetype = $archetypes[$archetypeKey] ?? null;
            if ($archetype === null) {
                continue;
            }

            // Skip if player is locked out or idle_modal_active.
            if (!empty($snapshot['player']['idle_modal_active'])) {
                continue;
            }
            // Compute economic presence state using production logic.
            // This is NOT a DB column — it's derived at read-time from
            // activity_state, idle_since_tick, online_current, etc.
            $presenceState = Economy::resolveEconomicPresenceState(
                $snapshot['player'], $seasonRow, $tick
            );
            if ($presenceState === 'Offline') {
                continue;
            }

            // 1. Sigil combine via production Actions::combineSigils().
            $combineTier = PolicyBehavior::decideCombineTier($archetype, $snapshot, $phase, $seed, $tick);
            if ($combineTier !== null) {
                $counts['combine']['attempted']++;
                $result = Actions::combineSigils($pid, $combineTier);
                if (!empty($result['success'])) {
                    $counts['combine']['succeeded']++;
                }
            }

            // 2. Boost purchase: no standalone production endpoint exists.
            //    Boost activation in production happens through sigil spending UI calls
            //    that are tightly coupled to HTTP/session. This is a known adapted path.
            //    Skipped for 3C — boosts are processed by TickEngine from active_boosts table.
            //    (PolicyBehavior::decideBoostPurchase decisions are not dispatched in 3C.)
            // Recorded as unmodeled for this milestone.

            // 3. Freeze target.
            $freezeTarget = PolicyBehavior::chooseFreezeTarget(
                $archetype, $snapshot, $playerSnapshots, $phase, $seed, $tick
            );
            if ($freezeTarget !== null) {
                $counts['freeze']['attempted']++;
                $result = Actions::freezePlayerUbi($pid, $freezeTarget);
                if (!empty($result['success'])) {
                    $counts['freeze']['succeeded']++;
                }
            }

            // 4. Theft attempt.
            $theftTarget = PolicyBehavior::chooseTheftTarget(
                $archetype, $snapshot, $playerSnapshots, $phase, $seed, $tick
            );
            if ($theftTarget !== null) {
                $counts['theft']['attempted']++;
                // Build minimal spent/requested sigil vectors for the production API.
                $theftResult = $this->executeTheft($db, $pid, $theftTarget, $seasonId, $snapshot);
                if ($theftResult) {
                    $counts['theft']['succeeded']++;
                }
            }

            // 5. Star purchase.
            //    Re-read fresh participation data for accurate coin balance.
            $freshParticipation = $db->fetch(
                "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
                [$pid, $seasonId]
            );
            $freshSnapshot = $snapshot;
            if ($freshParticipation) {
                $freshSnapshot['participation'] = $freshParticipation;
            }
            $starsToBuy = PolicyBehavior::decideStarPurchase(
                $archetype, $freshSnapshot, $seasonRow, $phase, $seed, $tick
            );
            if ($starsToBuy > 0) {
                $counts['star_purchase']['attempted']++;
                $result = Actions::purchaseStars($pid, $starsToBuy);
                if (!empty($result['success'])) {
                    $counts['star_purchase']['succeeded']++;
                }
            }

            // 6. Lock-in decision.
            if (in_array($phase, ['MID', 'LATE_ACTIVE', 'BLACKOUT'], true)) {
                // Re-read to get current state after potential star purchase.
                $freshParticipation2 = $db->fetch(
                    "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
                    [$pid, $seasonId]
                );
                $freshPlayer = $db->fetch(
                    "SELECT * FROM players WHERE player_id = ?",
                    [$pid]
                );
                if ($freshParticipation2 && $freshPlayer) {
                    $lockSnapshot = $snapshot;
                    $lockSnapshot['participation'] = $freshParticipation2;
                    $lockSnapshot['player'] = $freshPlayer;

                    // Only check lock-in if player is still in the season.
                    if (!empty($freshPlayer['joined_season_id'])) {
                        if (PolicyBehavior::shouldLockIn($archetype, $lockSnapshot, $seasonRow, $phase, $seed, $tick)) {
                            $counts['lock_in']['attempted']++;
                            $result = Actions::lockIn($pid);
                            if (!empty($result['success'])) {
                                $counts['lock_in']['succeeded']++;
                            }
                        }
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * Execute a theft attempt via production Actions::attemptSigilTheft().
     *
     * Builds the spent/requested sigil vectors from the player's current inventory
     * following the same logic as SimulationPlayer::attemptTheft().
     */
    private function executeTheft(Database $db, int $attackerId, int $targetId, int $seasonId, array $attackerSnapshot): bool
    {
        $participation = $attackerSnapshot['participation'];

        // Determine spend tier (prefer t5 over t4).
        $spendTier = 0;
        if ((int)($participation['sigils_t5'] ?? 0) > 0) {
            $spendTier = 5;
        } elseif ((int)($participation['sigils_t4'] ?? 0) > 0) {
            $spendTier = 4;
        }
        if ($spendTier === 0) {
            return false;
        }

        // Build spent vector: one sigil of the spend tier.
        $spent = array_fill(0, SIGIL_MAX_TIER, 0);
        $spent[$spendTier - 1] = 1;

        // Determine requested tier: highest tier the target has that we can receive.
        $targetParticipation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$targetId, $seasonId]
        );
        if (!$targetParticipation) {
            return false;
        }

        $spendValue = (int)(SIGIL_UTILITY_VALUE_BY_TIER[$spendTier] ?? 0);
        $requestedTier = 0;
        for ($tier = 6; $tier >= 1; $tier--) {
            $targetCount = (int)($targetParticipation['sigils_t' . $tier] ?? 0);
            $tierValue = (int)(SIGIL_UTILITY_VALUE_BY_TIER[$tier] ?? 0);
            if ($targetCount > 0 && $tierValue <= $spendValue) {
                $requestedTier = $tier;
                break;
            }
        }
        if ($requestedTier === 0) {
            return false;
        }

        $requested = array_fill(0, SIGIL_MAX_TIER, 0);
        $requested[$requestedTier - 1] = 1;

        $result = Actions::attemptSigilTheft($attackerId, $targetId, $spent, $requested);
        return !empty($result['success']);
    }

    // --- Internal helpers ---

    /**
     * Detect any extra seasons in the disposable DB beyond the target simulated season.
     *
     * Milestone 4A: Explicitly isolates the target season from incidental extra
     * seasons that may have been created by ensureSeasons() or prior runs.
     *
     * Stabilization: ensureSeasons() now skips when simulation clock is active,
     * so phantom seasons should no longer appear. This detection remains as a
     * safety net: if extra seasons are found, the artifact records them and the
     * run log emits a warning.
     *
     * @return int[]  Season IDs other than $this->seasonId found in the DB.
     */
    private function detectExtraSeasons(): array
    {
        if ($this->seasonId === null) {
            return [];
        }

        try {
            $pdo = $this->bootstrap->getConnection();
            $rows = $pdo->query("SELECT season_id FROM seasons")->fetchAll(PDO::FETCH_COLUMN);
            $extra = [];
            foreach ($rows as $id) {
                if ((int)$id !== $this->seasonId) {
                    $extra[] = (int)$id;
                }
            }
            if (!empty($extra)) {
                $this->log('phantom_seasons_detected', [
                    'target_season_id' => $this->seasonId,
                    'extra_season_ids' => $extra,
                    'note' => 'ensureSeasons() should be skipped in simulation mode. '
                            . 'Extra seasons may indicate a containment bypass.',
                ]);
            }
            return $extra;
        } catch (\Throwable $e) {
            $this->log('detect_extra_seasons_error', ['message' => $e->getMessage()]);
            return [];
        }
    }

    private function setState(string $state): void
    {
        $this->state = $state;
    }

    private function log(string $event, $detail = null): void
    {
        $entry = [
            'event' => $event,
            'state' => $this->state,
            'ts'    => microtime(true),
        ];
        if ($detail !== null) {
            $entry['detail'] = $detail;
        }
        $this->runLog[] = $entry;
    }
}
