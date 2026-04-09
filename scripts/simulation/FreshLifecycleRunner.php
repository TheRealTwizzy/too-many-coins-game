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
 * Responsibilities are split into bounded concerns:
 *   1. Safety/bootstrap  — delegated to FreshRunSafety + FreshRunBootstrap (Milestone 1)
 *   2. Tick-driving       — delegated to GameTime + TickEngine (Milestone 2)
 *   3. Cohort generation — CohortGenerator (Milestone 3B)
 *   4. Action orchestration — deferred to Milestone 3C+
 *
 * This class owns the lifecycle shell: validate → prepare → (future: run) → finalize.
 * It does NOT own synthetic player creation, action scheduling, or tick looping yet.
 */

require_once __DIR__ . '/FreshRunSafety.php';
require_once __DIR__ . '/FreshRunBootstrap.php';
require_once __DIR__ . '/CohortGenerator.php';

class FreshLifecycleRunner
{
    /** Run states for lifecycle tracking. */
    public const STATE_IDLE            = 'idle';
    public const STATE_VALIDATING      = 'validating';
    public const STATE_PREPARING       = 'preparing';
    public const STATE_READY           = 'ready';
    public const STATE_COHORT_CREATED  = 'cohort_created';
    public const STATE_RUNNING         = 'running';
    public const STATE_COMPLETED       = 'completed';
    public const STATE_FAILED          = 'failed';

    private FreshRunBootstrap $bootstrap;
    private string $state;
    private array $config;
    private array $runLog;
    private ?array $cohortManifest = null;

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
     * Milestone 3B: validates readiness, creates cohort, then returns.
     * Milestone 3C+ will add season join, tick loop, and finalization.
     *
     * @return array{status: string, message: string, cohort: ?array, adapted_paths: string[], unmodeled_mechanics: string[]}
     */
    public function run(): array
    {
        if ($this->state !== self::STATE_READY && $this->state !== self::STATE_COHORT_CREATED) {
            $this->log('run_rejected', ['state' => $this->state]);
            return [
                'status'              => 'rejected',
                'message'             => "Cannot run: state is '{$this->state}', expected 'ready' or 'cohort_created'. Call prepare() first.",
                'cohort'              => null,
                'adapted_paths'       => [],
                'unmodeled_mechanics' => [],
            ];
        }

        $this->setState(self::STATE_RUNNING);
        $this->log('run_started', [
            'seed'        => $this->config['seed'],
            'cohort_size' => $this->config['cohort_size'],
        ]);

        // --- Cohort generation (Milestone 3B) ---
        if ($this->cohortManifest === null) {
            // Reset to READY so createCohort() accepts
            $this->setState(self::STATE_READY);
            $cohortResult = $this->createCohort();
            if (($cohortResult['status'] ?? '') !== 'created') {
                return [
                    'status'              => 'failed',
                    'message'             => $cohortResult['message'] ?? 'Cohort generation failed.',
                    'cohort'              => $cohortResult,
                    'adapted_paths'       => [],
                    'unmodeled_mechanics' => [],
                ];
            }
            $this->setState(self::STATE_RUNNING);
        }

        // --- Milestone 3C+ will insert season join, tick loop, finalization here ---

        $this->setState(self::STATE_COMPLETED);
        $this->log('run_completed', [
            'total_players' => $this->cohortManifest['total_players'] ?? 0,
        ]);

        return [
            'status'              => 'completed',
            'message'             => 'Lifecycle run completed through cohort generation. Season join and tick orchestration will be added in Milestone 3C.',
            'cohort'              => $this->cohortManifest,
            'adapted_paths'       => $this->cohortManifest['adapted_paths'] ?? [],
            'unmodeled_mechanics' => ['season_join', 'tick_loop', 'action_scheduling', 'finalization'],
        ];
    }

    // --- Internal helpers ---

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
