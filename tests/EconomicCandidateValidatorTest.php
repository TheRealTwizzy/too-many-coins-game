<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/EconomicCandidateValidator.php';

class EconomicCandidateValidatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_candidate_validator_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testValidCandidatePackagePassesValidation(): void
    {
        $failures = EconomicCandidateValidator::validateCandidateDocument([
            'packages' => [
                [
                    'package_name' => 'safe-buff',
                    'changes' => [
                        [
                            'target' => 'base_ubi_active_per_tick',
                            'proposed_value' => 42,
                        ],
                    ],
                ],
            ],
            'scenarios' => [
                [
                    'name' => 'safe-buff-scenario',
                    'categories' => ['boost_related'],
                    'overrides' => [
                        'base_ubi_active_per_tick' => 42,
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $failures);
    }

    public function testUnknownKeyIsRejected(): void
    {
        $failures = EconomicCandidateValidator::validateCandidateDocument([
            'not_a_real_key' => 1,
        ]);

        $this->assertSame('candidate_unknown_key', $failures[0]['reason_code']);
    }

    public function testDeprecatedKeyIsRejected(): void
    {
        $failures = EconomicCandidateValidator::validateCandidateDocument([
            'starprice_model_version' => 2,
        ]);

        $this->assertSame('candidate_deprecated_key', $failures[0]['reason_code']);
    }

    public function testOutOfSurfaceKeyIsRejected(): void
    {
        $failures = EconomicCandidateValidator::validateCandidateDocument([
            'current_star_price' => 120,
        ]);

        $this->assertSame('candidate_out_of_surface', $failures[0]['reason_code']);
    }

    public function testDormantSearchSurfaceKeysAreRejected(): void
    {
        foreach ([
            'hoarding_window_ticks' => 120,
            'target_spend_rate_per_tick' => 18,
            'starprice_reactivation_window_ticks' => 90,
            'starprice_demand_table' => [['ratio_fp' => 1000000, 'multiplier_fp' => 1000000]],
            'market_affordability_bias_fp' => 970000,
            'vault_config' => [
                ['tier' => 1, 'supply' => 500, 'cost_table' => [['remaining' => 1, 'cost' => 50]]],
            ],
        ] as $key => $value) {
            $failures = EconomicCandidateValidator::validateCandidateDocument([
                $key => $value,
            ]);

            $this->assertSame('candidate_out_of_surface', $failures[0]['reason_code'], $key);
        }
    }

    public function testDisabledSubsystemKeyIsRejected(): void
    {
        $baseSeason = SimulationSeason::build(1, 'validator-disabled', [
            'hoarding_sink_enabled' => 0,
        ]);

        $failures = EconomicCandidateValidator::validateCandidateDocument([
            'hoarding_safe_hours' => 8,
        ], [
            'base_season' => $baseSeason,
        ]);

        $this->assertSame('candidate_disabled_subsystem', $failures[0]['reason_code']);
    }

    public function testTypeMismatchIsRejected(): void
    {
        $failures = EconomicCandidateValidator::validateCandidateDocument([
            'base_ubi_active_per_tick' => '42',
        ]);

        $this->assertSame('candidate_type_mismatch', $failures[0]['reason_code']);
    }

    public function testRangeViolationIsRejected(): void
    {
        $failures = EconomicCandidateValidator::validateCandidateDocument([
            'starprice_max_upstep_fp' => 0,
        ]);

        $this->assertSame('candidate_out_of_range', $failures[0]['reason_code']);
    }

    public function testScenarioCategoryMismatchIsRejected(): void
    {
        $failures = EconomicCandidateValidator::validateCandidateDocument([
            'name' => 'bad-scenario',
            'categories' => ['boost_related'],
            'overrides' => [
                'hoarding_tier1_rate_hourly_fp' => 350,
            ],
        ]);

        $this->assertContains('candidate_category_mismatch', array_column($failures, 'reason_code'));
    }

    public function testLintCliPassesForValidInput(): void
    {
        $inputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'valid_candidates.json';
        file_put_contents($inputPath, json_encode([
            'packages' => [
                [
                    'package_name' => 'safe-buff',
                    'changes' => [
                        ['target' => 'base_ubi_active_per_tick', 'proposed_value' => 42],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/lint_candidate_packages.php')
            . ' --input=' . escapeshellarg($inputPath);
        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }

    public function testLintCliFailsForInvalidInput(): void
    {
        $inputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'invalid_candidates.json';
        file_put_contents($inputPath, json_encode([
            'packages' => [
                [
                    'package_name' => 'bad-package',
                    'changes' => [
                        ['target' => 'current_star_price', 'proposed_value' => 120],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/lint_candidate_packages.php')
            . ' --input=' . escapeshellarg($inputPath);
        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(2, $exitCode, implode(PHP_EOL, $output));
        $this->assertStringContainsString('candidate_out_of_surface', implode(PHP_EOL, $output));
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
