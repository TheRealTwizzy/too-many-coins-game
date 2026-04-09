<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/FreshLifecycleRunner.php';

/**
 * Tests for the FreshLifecycleRunner orchestration shell (Milestone 3A).
 *
 * These tests validate the runner skeleton behaviour without requiring a
 * live database — they exercise state machine transitions, prerequisite
 * validation delegation, and the stub execution entrypoint.
 */
class FreshLifecycleRunnerTest extends TestCase
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

    private function setFreshRunEnv(bool $destructive = false): void
    {
        putenv(FreshRunSafety::ENV_SIMULATION_MODE . '=fresh-run');
        if ($destructive) {
            putenv(FreshRunSafety::ENV_DESTRUCTIVE_RESET . '=1');
        } else {
            putenv(FreshRunSafety::ENV_DESTRUCTIVE_RESET);
        }
    }

    private function safeConfig(array $overrides = []): array
    {
        return array_merge([
            'db_host'     => '127.0.0.1',
            'db_port'     => '3306',
            'db_name'     => 'tmc_sim_test_lifecycle',
            'db_user'     => 'root',
            'db_pass'     => '',
            'seed'        => 42,
            'cohort_size' => 10,
            'drop_first'  => false,
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // Construction and initial state
    // -----------------------------------------------------------------------

    public function testInitialStateIsIdle(): void
    {
        $runner = new FreshLifecycleRunner($this->safeConfig());
        $this->assertSame(FreshLifecycleRunner::STATE_IDLE, $runner->getState());
    }

    public function testRunLogIsEmptyOnConstruction(): void
    {
        $runner = new FreshLifecycleRunner($this->safeConfig());
        $this->assertSame([], $runner->getRunLog());
    }

    public function testConfigDefaultsApplied(): void
    {
        $runner = new FreshLifecycleRunner(['db_host' => '127.0.0.1']);
        $config = $runner->getConfig();
        $this->assertSame(42, $config['seed']);
        $this->assertSame(100, $config['cohort_size']);
        $this->assertFalse($config['drop_first']);
    }

    public function testConfigOverridesApplied(): void
    {
        $runner = new FreshLifecycleRunner($this->safeConfig([
            'seed'        => 99,
            'cohort_size' => 5,
        ]));
        $config = $runner->getConfig();
        $this->assertSame(99, $config['seed']);
        $this->assertSame(5, $config['cohort_size']);
    }

    // -----------------------------------------------------------------------
    // Validation — delegates to FreshRunSafety
    // -----------------------------------------------------------------------

    public function testValidatePassesWithSafeEnv(): void
    {
        $this->setFreshRunEnv();
        $runner = new FreshLifecycleRunner($this->safeConfig());
        $errors = $runner->validate();
        $this->assertSame([], $errors);
    }

    public function testValidateFailsWithoutSimulationMode(): void
    {
        putenv(FreshRunSafety::ENV_SIMULATION_MODE);
        $runner = new FreshLifecycleRunner($this->safeConfig());
        $errors = $runner->validate();
        $this->assertNotEmpty($errors);
        $this->assertSame(FreshLifecycleRunner::STATE_FAILED, $runner->getState());
    }

    public function testValidateFailsOnUnsafeDbName(): void
    {
        $this->setFreshRunEnv();
        $runner = new FreshLifecycleRunner($this->safeConfig(['db_name' => 'too_many_coins']));
        $errors = $runner->validate();
        $this->assertNotEmpty($errors);
        $this->assertSame(FreshLifecycleRunner::STATE_FAILED, $runner->getState());
    }

    public function testValidateFailsOnNonLocalHost(): void
    {
        $this->setFreshRunEnv();
        $runner = new FreshLifecycleRunner($this->safeConfig(['db_host' => '10.0.0.1']));
        $errors = $runner->validate();
        $this->assertNotEmpty($errors);
    }

    public function testValidateLogsOnFailure(): void
    {
        putenv(FreshRunSafety::ENV_SIMULATION_MODE);
        $runner = new FreshLifecycleRunner($this->safeConfig());
        $runner->validate();
        $log = $runner->getRunLog();
        $this->assertNotEmpty($log);
        $this->assertSame('validation_failed', $log[0]['event']);
    }

    public function testValidateLogsOnSuccess(): void
    {
        $this->setFreshRunEnv();
        $runner = new FreshLifecycleRunner($this->safeConfig());
        $runner->validate();
        $log = $runner->getRunLog();
        $this->assertNotEmpty($log);
        $this->assertSame('validation_passed', $log[0]['event']);
    }

    // -----------------------------------------------------------------------
    // Run — state gating
    // -----------------------------------------------------------------------

    public function testRunRejectsFromIdleState(): void
    {
        $runner = new FreshLifecycleRunner($this->safeConfig());
        $result = $runner->run();
        $this->assertSame('rejected', $result['status']);
        $this->assertStringContainsString('idle', $result['message']);
    }

    public function testRunRejectsFromFailedState(): void
    {
        putenv(FreshRunSafety::ENV_SIMULATION_MODE);
        $runner = new FreshLifecycleRunner($this->safeConfig());
        $runner->validate(); // puts state to FAILED
        $result = $runner->run();
        $this->assertSame('rejected', $result['status']);
        $this->assertStringContainsString('failed', $result['message']);
    }

    // -----------------------------------------------------------------------
    // Run stub result structure (Milestone 3A skeleton)
    // -----------------------------------------------------------------------

    public function testRunStubReturnsExpectedStructure(): void
    {
        $this->setFreshRunEnv();
        $runner = new FreshLifecycleRunner($this->safeConfig());

        // We can't call prepare() without a real DB, so manually set state to ready
        // to test the stub execution path. This is a test-only shortcut.
        $ref = new ReflectionClass($runner);
        $prop = $ref->getProperty('state');
        $prop->setValue($runner, FreshLifecycleRunner::STATE_READY);

        $result = $runner->run();
        $this->assertSame('completed_stub', $result['status']);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('adapted_paths', $result);
        $this->assertArrayHasKey('unmodeled_mechanics', $result);
        $this->assertIsArray($result['adapted_paths']);
        $this->assertIsArray($result['unmodeled_mechanics']);
    }

    public function testRunStubTransitionsToCompleted(): void
    {
        $this->setFreshRunEnv();
        $runner = new FreshLifecycleRunner($this->safeConfig());

        $ref = new ReflectionClass($runner);
        $prop = $ref->getProperty('state');
        $prop->setValue($runner, FreshLifecycleRunner::STATE_READY);

        $runner->run();
        $this->assertSame(FreshLifecycleRunner::STATE_COMPLETED, $runner->getState());
    }

    public function testRunStubRecordsUnmodeledMechanics(): void
    {
        $this->setFreshRunEnv();
        $runner = new FreshLifecycleRunner($this->safeConfig());

        $ref = new ReflectionClass($runner);
        $prop = $ref->getProperty('state');
        $prop->setValue($runner, FreshLifecycleRunner::STATE_READY);

        $result = $runner->run();
        $this->assertContains('cohort_generation', $result['unmodeled_mechanics']);
        $this->assertContains('tick_loop', $result['unmodeled_mechanics']);
        $this->assertContains('action_scheduling', $result['unmodeled_mechanics']);
        $this->assertContains('finalization', $result['unmodeled_mechanics']);
    }

    public function testRunLogsStartAndCompletion(): void
    {
        $this->setFreshRunEnv();
        $runner = new FreshLifecycleRunner($this->safeConfig());

        $ref = new ReflectionClass($runner);
        $prop = $ref->getProperty('state');
        $prop->setValue($runner, FreshLifecycleRunner::STATE_READY);

        $runner->run();
        $log = $runner->getRunLog();
        $events = array_column($log, 'event');
        $this->assertContains('run_started', $events);
        $this->assertContains('run_completed_stub', $events);
    }

    // -----------------------------------------------------------------------
    // State constants are distinct
    // -----------------------------------------------------------------------

    public function testStateConstantsAreDistinct(): void
    {
        $states = [
            FreshLifecycleRunner::STATE_IDLE,
            FreshLifecycleRunner::STATE_VALIDATING,
            FreshLifecycleRunner::STATE_PREPARING,
            FreshLifecycleRunner::STATE_READY,
            FreshLifecycleRunner::STATE_RUNNING,
            FreshLifecycleRunner::STATE_COMPLETED,
            FreshLifecycleRunner::STATE_FAILED,
        ];
        $this->assertCount(count($states), array_unique($states), 'All state constants must be unique');
    }
}
