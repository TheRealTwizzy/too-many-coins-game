<?php

use PHPUnit\Framework\TestCase;

class SimulationCandidatePatchCliTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_candidate_patch_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testContractCliCandidatePatchIsAuditedAsActive(): void
    {
        $patchPath = $this->tempDir . DIRECTORY_SEPARATOR . 'idle-viability.patch.json';
        file_put_contents($patchPath, json_encode([
            'base_ubi_idle_factor_fp' => 300000,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $outputDir = $this->tempDir . DIRECTORY_SEPARATOR . 'contracts';
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../scripts/simulate_contracts.php')
            . ' --seed=cli-candidate-patch'
            . ' --candidate-patch=' . escapeshellarg($patchPath)
            . ' --output=' . escapeshellarg($outputDir);

        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
        $auditPath = $outputDir . DIRECTORY_SEPARATOR . 'contract_cli-candidate-patch.audit' . DIRECTORY_SEPARATOR . 'effective_config.json';
        $this->assertFileExists($auditPath);

        $audit = json_decode((string)file_get_contents($auditPath), true);
        $this->assertIsArray($audit);
        $this->assertSame(300000, (int)$audit['effective_config']['season']['base_ubi_idle_factor_fp']);

        $changes = (array)($audit['requested_candidate_changes'] ?? []);
        $this->assertCount(1, $changes);
        $change = $changes[0];
        $this->assertSame('season.base_ubi_idle_factor_fp', (string)$change['path']);
        $this->assertSame(300000, (int)$change['effective_value']);
        $this->assertSame('candidate_patch', (string)$change['effective_source']);
        $this->assertTrue((bool)$change['is_active']);
    }

    public function testBaselineBatchRejectsMissingCandidatePatchBeforeDryRun(): void
    {
        $seasonConfigPath = $this->tempDir . DIRECTORY_SEPARATOR . 'season.json';
        file_put_contents($seasonConfigPath, json_encode([
            'base_ubi_active_per_tick' => 30,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $missingPatchPath = $this->tempDir . DIRECTORY_SEPARATOR . 'missing.patch.json';
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../scripts/run_baseline_batch.php')
            . ' --season-config=' . escapeshellarg($seasonConfigPath)
            . ' --candidate-patch=' . escapeshellarg($missingPatchPath)
            . ' --output=' . escapeshellarg($this->tempDir . DIRECTORY_SEPARATOR . 'batch')
            . ' --dry-run';

        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertNotSame(0, $exitCode, implode(PHP_EOL, $output));
        $this->assertStringContainsString('Candidate patch not found', implode(PHP_EOL, $output));
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
