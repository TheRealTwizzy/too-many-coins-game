<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/ExperimentCapsuleRunner.php';

class ExperimentCapsuleRunnerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_experiment_capsule_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testEconomyOnlyCapsuleWritesExperimentArtifactsAndPassDecision(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'] = [];

        $result = ExperimentCapsuleRunner::generate($proposal, [
            'output_root' => $this->tempDir,
        ]);

        $this->assertSame('streak_pressure_qol_capsule', $result['experiment_id']);
        $this->assertSame('pass', $result['decision_record']['status']);
        $this->assertFileExists($result['experiment_artifact_paths']['experiment_brief_json']);
        $this->assertFileExists($result['experiment_artifact_paths']['experience_gate_report_json']);
        $this->assertFileExists($result['experiment_artifact_paths']['decision_record_json']);
    }

    public function testCapsuleWithUnapprovedExperienceChangeNeedsRevision(): void
    {
        $result = ExperimentCapsuleRunner::generate($this->proposal(), [
            'output_root' => $this->tempDir,
        ]);

        $this->assertSame('revise', $result['decision_record']['status']);
        $this->assertSame('pending_approval', $result['experience_gate_report']['status']);
    }

    public function testCapsuleWithApprovedExperienceChangePasses(): void
    {
        $result = ExperimentCapsuleRunner::generate($this->proposal(), [
            'output_root' => $this->tempDir,
            'approval_manifest' => [
                'schema_version' => 'tmc-feature-approval.v1',
                'mechanic_id' => 'daily_streak_pressure',
                'bundle_hash' => 'deferred',
                'allowed_path_classes' => ['frontend'],
                'allowed_paths' => ['public/js/app.js'],
                'approval_reason' => 'Tiny HUD readability polish approved for capsule testing.',
                'approver' => 'trent',
                'approved_at' => '2026-04-26T12:00:00Z',
            ],
            'approval_manifest_bundle_hash_mode' => 'use_generated_hash',
        ]);

        $this->assertSame('pass', $result['decision_record']['status']);
        $this->assertSame('pass', $result['experience_gate_report']['status']);
    }

    public function testTooBroadCapsuleSplitsToDualLane(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'] = [
            $this->experienceChange('public/js/app.js', 'HUD copy polish'),
            $this->experienceChange('public/css/style.css', 'HUD spacing polish'),
            $this->experienceChange('public/index.html', 'View label polish'),
            $this->experienceChange('api/index.php', 'Read-only response label polish'),
        ];

        $result = ExperimentCapsuleRunner::generate($proposal, [
            'output_root' => $this->tempDir,
        ]);

        $this->assertSame('split_to_dual_lane', $result['decision_record']['status']);
        $this->assertSame('dual_lane', $result['experiment_brief']['recommended_mode']);
    }

    private function proposal(): array
    {
        return [
            'experiment_id' => 'streak_pressure_qol_capsule',
            'mode_preference' => 'capsule',
            'change_intent' => 'combined',
            'economy_hypothesis' => 'Slightly stronger active UBI can improve regular-player viability without making hoarding dominant.',
            'player_facing_intent' => 'Make the daily rhythm easier to understand.',
            'rollback_notes' => 'Revert the candidate patch and remove the HUD copy polish from the capsule bundle.',
            'experience_changes' => [
                $this->experienceChange('public/js/app.js', 'Clarify the daily rhythm HUD label.'),
            ],
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

    private function experienceChange(string $path, string $summary): array
    {
        return [
            'path' => $path,
            'change_type' => 'hud_readability',
            'summary' => $summary,
            'economy_behavior' => 'none',
            'reversible' => true,
            'qa_evidence' => ['manual_hud_smoke'],
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
