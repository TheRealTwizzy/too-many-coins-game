<?php

use PHPUnit\Framework\TestCase;

class ExperimentCapsuleCliTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_experiment_capsule_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testCliGeneratesExperimentCapsule(): void
    {
        $proposalPath = $this->tempDir . DIRECTORY_SEPARATOR . 'proposal.json';
        file_put_contents($proposalPath, json_encode($this->proposal(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../scripts/generate_experiment_capsule.php')
            . ' --proposal=' . escapeshellarg($proposalPath)
            . ' --output=' . escapeshellarg($this->tempDir . DIRECTORY_SEPARATOR . 'bundles');

        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
        $this->assertStringContainsString('Experiment Capsule', implode(PHP_EOL, $output));
        $this->assertStringContainsString('Decision: revise', implode(PHP_EOL, $output));
        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'bundles' . DIRECTORY_SEPARATOR . 'daily_streak_pressure' . DIRECTORY_SEPARATOR . 'decision_record.json');
    }

    public function testCliFailsForInvalidExperimentProposal(): void
    {
        $proposal = $this->proposal();
        $proposal['mode_preference'] = 'fast_track';
        $proposalPath = $this->tempDir . DIRECTORY_SEPARATOR . 'invalid.json';
        file_put_contents($proposalPath, json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../scripts/generate_experiment_capsule.php')
            . ' --proposal=' . escapeshellarg($proposalPath)
            . ' --output=' . escapeshellarg($this->tempDir . DIRECTORY_SEPARATOR . 'invalid-bundles');

        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(1, $exitCode, implode(PHP_EOL, $output));
        $this->assertStringContainsString('Experiment brief validation failed', implode(PHP_EOL, $output));
        $this->assertStringContainsString('invalid_mode_preference', implode(PHP_EOL, $output));
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
            'experience_changes' => [[
                'path' => 'public/js/app.js',
                'change_type' => 'hud_readability',
                'summary' => 'Clarify the daily rhythm HUD label.',
                'economy_behavior' => 'none',
                'reversible' => true,
                'qa_evidence' => ['manual_hud_smoke'],
            ]],
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
