<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/SimulationConfigPreflight.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPopulationSeason.php';
require_once __DIR__ . '/../includes/boost_catalog.php';

class SimulationConfigPreflightTest extends TestCase
{
    private string $tempDir;
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_preflight_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->envBackup = [
            'TMC_TICK_REAL_SECONDS' => getenv('TMC_TICK_REAL_SECONDS'),
            SimulationConfigPreflight::AUDIT_ENV_BYPASS => getenv(SimulationConfigPreflight::AUDIT_ENV_BYPASS),
        ];
        putenv(SimulationConfigPreflight::AUDIT_ENV_BYPASS);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false || $value === null || $value === '') {
                putenv($key);
                continue;
            }
            putenv($key . '=' . $value);
        }

        $this->deleteDir($this->tempDir);
    }

    public function testFeatureDisabledButKnobChangedFailsPreflight(): void
    {
        try {
            SimulationConfigPreflight::resolve($this->options([
                'base_season_overrides' => ['hoarding_sink_enabled' => 0],
                'candidate_patch' => ['hoarding_tier1_rate_hourly_fp' => 350],
            ]));
            $this->fail('Expected preflight to fail for disabled hoarding sink tuning.');
        } catch (SimulationConfigPreflightException $e) {
            $failure = $e->report()['candidate_validation']['candidate_patch_failures'][0];
            $this->assertSame('candidate_disabled_subsystem', $failure['reason_code']);
            $this->assertFileExists($e->artifactPaths()['effective_config_json']);
            $this->assertFileExists($e->artifactPaths()['effective_config_audit_md']);
        }
    }

    public function testEnvOverrideShadowsRuntimeCandidateValue(): void
    {
        putenv('TMC_TICK_REAL_SECONDS=3600');

        try {
            SimulationConfigPreflight::resolve($this->options([
                'candidate_patch' => [
                    ['path' => 'runtime.tick_real_seconds', 'value' => 60],
                ],
            ]));
            $this->fail('Expected runtime candidate path to be rejected by strict validator.');
        } catch (SimulationConfigPreflightException $e) {
            $failure = $e->report()['candidate_validation']['candidate_patch_failures'][0];
            $this->assertSame('candidate_out_of_surface', $failure['reason_code']);
        }
    }

    public function testScenarioOverrideShadowsCandidateValue(): void
    {
        putenv(SimulationConfigPreflight::AUDIT_ENV_BYPASS . '=1');

        $resolved = SimulationConfigPreflight::resolve($this->options([
            'candidate_patch' => ['base_ubi_active_per_tick' => 42],
            'scenario_overrides' => ['base_ubi_active_per_tick' => 50],
        ]));

        $change = $resolved['report']['requested_candidate_changes'][0];
        $this->assertFalse($change['is_active']);
        $this->assertSame('inactive_shadowed', $change['reason_code']);
        $this->assertSame(50, $change['effective_value']);
        $this->assertSame('scenario_override', $change['effective_source']);
    }

    public function testInvalidConfigPathFailsPreflight(): void
    {
        try {
            SimulationConfigPreflight::resolve($this->options([
                'candidate_patch' => [
                    ['path' => 'season.not_a_real_key', 'value' => 1],
                ],
            ]));
            $this->fail('Expected invalid config path to fail preflight.');
        } catch (SimulationConfigPreflightException $e) {
            $failure = $e->report()['candidate_validation']['candidate_patch_failures'][0];
            $this->assertSame('candidate_unknown_key', $failure['reason_code']);
        }
    }

    public function testDeprecatedConfigKeyFailsPreflight(): void
    {
        try {
            SimulationConfigPreflight::resolve($this->options([
                'candidate_patch' => ['starprice_model_version' => 2],
            ]));
            $this->fail('Expected deprecated key to fail preflight.');
        } catch (SimulationConfigPreflightException $e) {
            $failure = $e->report()['candidate_validation']['candidate_patch_failures'][0];
            $this->assertSame('candidate_deprecated_key', $failure['reason_code']);
        }
    }

    public function testDormantSearchSurfaceKeyFailsPreflight(): void
    {
        try {
            SimulationConfigPreflight::resolve($this->options([
                'candidate_patch' => ['target_spend_rate_per_tick' => 42],
            ]));
            $this->fail('Expected dormant search-surface key to fail preflight.');
        } catch (SimulationConfigPreflightException $e) {
            $failure = $e->report()['candidate_validation']['candidate_patch_failures'][0];
            $this->assertSame('candidate_out_of_surface', $failure['reason_code']);
        }
    }

    public function testSuccessfulActiveKeyResolutionWritesArtifacts(): void
    {
        $resolved = SimulationConfigPreflight::resolve($this->options([
            'candidate_patch' => ['base_ubi_active_per_tick' => 42],
        ]));

        $change = $resolved['report']['requested_candidate_changes'][0];
        $this->assertTrue($change['is_active']);
        $this->assertNull($change['reason_code']);
        $this->assertSame(42, $change['effective_value']);
        $this->assertSame('candidate_patch', $change['effective_source']);
        $this->assertSame('pass', $resolved['report']['status']);
        $this->assertFileExists($resolved['artifact_paths']['effective_config_json']);
        $this->assertFileExists($resolved['artifact_paths']['effective_config_audit_md']);
    }

    public function testRuntimeAuditIncludesSigilScarcityControls(): void
    {
        $resolved = SimulationConfigPreflight::resolve($this->options());
        $runtime = $resolved['report']['effective_config']['runtime'];

        $this->assertSame((string)SIGIL_DROP_ALGORITHM_VERSION, $runtime['sigil_drop_algorithm_version']);
        $this->assertSame((int)SIGIL_DROP_CHANCE_FP, $runtime['sigil_drop_chance_fp']);
        $this->assertSame((int)SIGIL_INVENTORY_TOTAL_CAP, $runtime['sigil_inventory_total_cap']);
        $this->assertSame((int)SIGIL_INVENTORY_DROP_PRESSURE_START, $runtime['sigil_inventory_drop_pressure_start']);
        $this->assertSame((int)SIGIL_INVENTORY_DROP_PRESSURE_FULL, $runtime['sigil_inventory_drop_pressure_full']);
        $this->assertSame((int)SIGIL_BOOST_DROP_PRESSURE_STEP_FP, $runtime['sigil_boost_drop_pressure_step_fp']);
        $this->assertSame((int)SIGIL_BOOST_DROP_PRESSURE_STEP_PENALTY_FP, $runtime['sigil_boost_drop_pressure_step_penalty_fp']);
        $this->assertSame((int)SIGIL_BOOST_DROP_PRESSURE_MIN_FP, $runtime['sigil_boost_drop_pressure_min_fp']);
        $this->assertSame((int)BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT, $runtime['boost_time_cap_seconds_per_product']);
        $this->assertSame((int)BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION, $runtime['boost_recovery_seconds_after_session']);
        $this->assertSame(array_values(SIGIL_FREEZE_SPEND_TIERS), $runtime['sigil_freeze_spend_tiers']);
        $this->assertSame(SIGIL_FREEZE_DURATION_TICKS_BY_TIER, $runtime['sigil_freeze_duration_ticks_by_tier']);
        $this->assertSame(SIGIL_FREEZE_STACK_EXTENSION_TICKS_BY_TIER, $runtime['sigil_freeze_stack_extension_ticks_by_tier']);
        $this->assertSame(array_values(SIGIL_THEFT_SPEND_TIERS), $runtime['sigil_theft_spend_tiers']);
    }

    public function testSimulationRunCarriesConfigAuditMetadata(): void
    {
        $auditDir = $this->tempDir . DIRECTORY_SEPARATOR . 'run.audit';
        $payload = SimulationPopulationSeason::run('preflight-run', 1, null, [
            'candidate_patch' => ['base_ubi_active_per_tick' => 42],
            'preflight_artifact_dir' => $auditDir,
            'run_label' => 'preflight-run',
        ]);

        $this->assertSame('pass', (string)$payload['config_audit']['status']);
        $this->assertFileExists((string)$payload['config_audit']['artifact_paths']['effective_config_json']);
        $this->assertFileExists((string)$payload['config_audit']['artifact_paths']['effective_config_audit_md']);
    }

    public function testMarketAffordabilityBiasFpIsNowLiveAndAcceptedAsCandidate(): void
    {
        $resolved = SimulationConfigPreflight::resolve($this->options([
            'candidate_patch' => ['market_affordability_bias_fp' => 940000],
        ]));

        $changes = $resolved['report']['requested_candidate_changes'];
        $found = false;
        foreach ($changes as $change) {
            if (($change['raw_path'] ?? '') === 'market_affordability_bias_fp'
                || ($change['path'] ?? '') === 'season.market_affordability_bias_fp') {
                $this->assertTrue((bool)$change['is_active'],
                    'market_affordability_bias_fp must be active after wiring into calculateStarPrice()');
                $found = true;
            }
        }
        $this->assertTrue($found, 'market_affordability_bias_fp must appear in requested_candidate_changes');
    }

    private function options(array $overrides = []): array
    {
        return array_merge([
            'seed' => 'preflight-seed',
            'season_id' => 1,
            'simulator' => 'B',
            'players_per_archetype' => 1,
            'artifact_dir' => $this->tempDir . DIRECTORY_SEPARATOR . 'audit_' . uniqid(),
        ], $overrides);
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($fullPath)) {
                $this->deleteDir($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
