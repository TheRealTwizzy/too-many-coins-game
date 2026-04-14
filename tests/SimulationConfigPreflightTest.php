<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/SimulationConfigPreflight.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPopulationSeason.php';

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
            $change = $e->report()['requested_candidate_changes'][0];
            $this->assertSame('inactive_feature_disabled', $change['reason_code']);
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
            $this->fail('Expected runtime candidate path to be shadowed by env.');
        } catch (SimulationConfigPreflightException $e) {
            $change = $e->report()['requested_candidate_changes'][0];
            $this->assertSame('inactive_shadowed', $change['reason_code']);
            $this->assertSame(3600, $change['effective_value']);
            $this->assertSame('environment:TMC_TICK_REAL_SECONDS', $change['effective_source']);
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
            $change = $e->report()['requested_candidate_changes'][0];
            $this->assertSame('inactive_invalid_path', $change['reason_code']);
        }
    }

    public function testUnreferencedConfigKeyFailsPreflight(): void
    {
        try {
            SimulationConfigPreflight::resolve($this->options([
                'candidate_patch' => ['vault_config' => '[]'],
            ]));
            $this->fail('Expected unreferenced key to fail preflight.');
        } catch (SimulationConfigPreflightException $e) {
            $change = $e->report()['requested_candidate_changes'][0];
            $this->assertSame('inactive_unreferenced', $change['reason_code']);
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
