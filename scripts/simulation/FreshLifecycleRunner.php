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
 * Responsibilities are split into bounded concerns:
 *   1. Safety/bootstrap  — delegated to FreshRunSafety + FreshRunBootstrap (Milestone 1)
 *   2. Tick-driving       — delegated to GameTime + TickEngine (Milestone 2)
 *   3. Cohort/action orchestration — deferred to Milestone 3B+
 *
 * This class owns the lifecycle shell: validate → prepare → (future: run) → finalize.
 * It does NOT own synthetic player creation, action scheduling, or tick looping yet.
 */

require_once __DIR__ . '/FreshRunSafety.php';
require_once __DIR__ . '/FreshRunBootstrap.php';

class FreshLifecycleRunner
{
    /** Run states for lifecycle tracking. */
    public const STATE_IDLE       = 'idle';
    public const STATE_VALIDATING = 'validating';
    public const STATE_PREPARING  = 'preparing';
    public const STATE_READY      = 'ready';
    public const STATE_RUNNING    = 'running';
    public const STATE_COMPLETED  = 'completed';
    public const STATE_FAILED     = 'failed';

    private FreshRunBootstrap $bootstrap;
    private string $state;
    private array $config;
    private array $runLog;

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
     * Execute the fresh lifecycle run.
     *
     * Milestone 3A: skeleton only — validates readiness and returns a stub result.
     * Milestone 3B+ will add cohort generation, tick loop, and finalization here.
     *
     * @return array{status: string, message: string, adapted_paths: string[], unmodeled_mechanics: string[]}
     */
    public function run(): array
    {
        if ($this->state !== self::STATE_READY) {
            $this->log('run_rejected', ['state' => $this->state]);
            return [
                'status'              => 'rejected',
                'message'             => "Cannot run: state is '{$this->state}', expected 'ready'. Call prepare() first.",
                'adapted_paths'       => [],
                'unmodeled_mechanics' => [],
            ];
        }

        $this->setState(self::STATE_RUNNING);
        $this->log('run_started', [
            'seed'        => $this->config['seed'],
            'cohort_size' => $this->config['cohort_size'],
        ]);

        // --- Milestone 3B+ will insert cohort generation, tick loop, finalization here ---

        $this->setState(self::STATE_COMPLETED);
        $this->log('run_completed_stub');

        return [
            'status'              => 'completed_stub',
            'message'             => 'Lifecycle runner shell executed successfully. Cohort generation and tick orchestration will be added in Milestone 3B.',
            'adapted_paths'       => [],
            'unmodeled_mechanics' => ['cohort_generation', 'tick_loop', 'action_scheduling', 'finalization'],
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
