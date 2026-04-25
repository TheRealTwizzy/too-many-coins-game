<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/MechanicBrief.php';
require_once __DIR__ . '/../scripts/feature_factory/BalanceImpactModel.php';
require_once __DIR__ . '/../scripts/feature_factory/MechanicScaffolder.php';

class FeatureFactoryScaffolderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_feature_factory_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testScaffolderWritesExpectedBundleArtifacts(): void
    {
        $brief = MechanicBrief::fromProposal($this->proposal());
        $balance = BalanceImpactModel::buildReport($brief);

        $result = MechanicScaffolder::writeBundle($brief, $balance, [
            'output_root' => $this->tempDir,
        ]);

        $this->assertFileExists($result['artifact_paths']['mechanic_brief_json']);
        $this->assertFileExists($result['artifact_paths']['mechanic_brief_md']);
        $this->assertFileExists($result['artifact_paths']['balance_impact_report_json']);
        $this->assertFileExists($result['artifact_paths']['candidate_patch_template_json']);
        $this->assertFileExists($result['artifact_paths']['mechanic_contract_checklist_md']);
        $this->assertFileExists($result['artifact_paths']['approval_manifest_example_json']);
        $this->assertFileExists($result['artifact_paths']['implementation_plan_draft_md']);
        $this->assertFileExists($result['artifact_paths']['patch_classification_json']);
        $this->assertSame([], $result['classification']['blocked_pre_approval']);

        $candidate = json_decode((string)file_get_contents($result['artifact_paths']['candidate_patch_template_json']), true);
        $this->assertSame('feature_factory_daily_streak_pressure', $candidate['packages'][0]['package_name']);
        $this->assertSame('base_ubi_active_per_tick', $candidate['packages'][0]['changes'][0]['target']);
        $this->assertSame(42, $candidate['packages'][0]['changes'][0]['proposed_value']);
        $this->assertSame('pass', $result['candidate_validation']['status']);
    }

    public function testProposedOnlyKeysDoNotEnterCandidateTemplate(): void
    {
        $proposal = $this->proposal();
        $proposal['tunable_parameters'] = [
            [
                'key' => 'streak_decay_rate_fp',
                'kind' => 'proposed',
                'proposed_value' => 900000,
                'reason' => 'Future runtime key.',
            ],
        ];
        $brief = MechanicBrief::fromProposal($proposal);
        $balance = BalanceImpactModel::buildReport($brief);

        $result = MechanicScaffolder::writeBundle($brief, $balance, [
            'output_root' => $this->tempDir,
        ]);

        $candidate = json_decode((string)file_get_contents($result['artifact_paths']['candidate_patch_template_json']), true);
        $this->assertSame([], $candidate['packages']);
        $this->assertSame('skipped_no_existing_patchable_keys', $result['candidate_validation']['status']);
    }

    private function proposal(): array
    {
        return [
            'mechanic_id' => 'daily_streak_pressure',
            'title' => 'Regular play streak pressure',
            'summary' => 'Reward repeat daily participation without making hoarding dominant.',
            'player_fantasy' => 'Consistent players feel their daily rhythm matters.',
            'affected_systems' => ['ubi', 'hoarding_pressure'],
            'primary_strategy' => 'regular',
            'secondary_strategies' => ['hoarder', 'mostly_idle'],
            'counterplay' => ['Spend earlier to avoid becoming an easy hoarding target.'],
            'failure_modes' => ['dominant_strategy_risk', 'hoarding_abuse', 'onboarding_harm'],
            'tunable_parameters' => [
                ['key' => 'base_ubi_active_per_tick', 'kind' => 'existing', 'proposed_value' => 42, 'reason' => 'Model reward pressure.'],
            ],
            'proposed_new_config_keys' => [
                ['key' => 'streak_decay_rate_fp', 'type' => 'int', 'reason' => 'Future runtime key for streak decay.'],
            ],
            'required_metrics' => [
                'viability' => ['archetype_viability_min_ratio'],
                'concentration_or_diversity' => ['strategic_diversity'],
                'mechanic_specific' => ['streak_completion_density'],
            ],
            'archetype_expectations' => [
                'hoarder' => 'down',
                'mostly_idle' => 'down',
                'regular' => 'up',
                'hardcore' => 'flat',
                'boost_focused' => 'flat',
                'star_focused' => 'flat',
                'early_locker' => 'flat',
                'late_deployer' => 'down',
                'casual' => 'up',
                'aggressive_sigil_user' => 'flat',
            ],
        ];
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach ((array)scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->deleteDir($full);
                continue;
            }
            @unlink($full);
        }
        @rmdir($path);
    }
}
