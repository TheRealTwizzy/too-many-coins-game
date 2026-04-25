<?php

use PHPUnit\Framework\TestCase;

class FeatureFactoryCliTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_feature_factory_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testCliGeneratesBundle(): void
    {
        $proposalPath = $this->tempDir . DIRECTORY_SEPARATOR . 'proposal.json';
        file_put_contents($proposalPath, json_encode($this->proposal(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../scripts/generate_feature_factory_bundle.php')
            . ' --proposal=' . escapeshellarg($proposalPath)
            . ' --output=' . escapeshellarg($this->tempDir . DIRECTORY_SEPARATOR . 'bundles');

        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
        $this->assertStringContainsString('Feature Factory Bundle', implode(PHP_EOL, $output));
        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'bundles' . DIRECTORY_SEPARATOR . 'daily_streak_pressure' . DIRECTORY_SEPARATOR . 'mechanic_brief.json');
    }

    public function testCliFailsWhenPlannedRuntimePathIsProvidedWithoutApproval(): void
    {
        $proposal = $this->proposal();
        $proposal['planned_patch_paths'] = ['includes/economy.php'];
        $proposalPath = $this->tempDir . DIRECTORY_SEPARATOR . 'proposal-blocked.json';
        file_put_contents($proposalPath, json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../scripts/generate_feature_factory_bundle.php')
            . ' --proposal=' . escapeshellarg($proposalPath)
            . ' --output=' . escapeshellarg($this->tempDir . DIRECTORY_SEPARATOR . 'blocked');

        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(1, $exitCode, implode(PHP_EOL, $output));
        $this->assertStringContainsString('pre-approval guard blocked', implode(PHP_EOL, $output));
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
