param(
  [Parameter(Mandatory=$true)]
  [ValidatePattern(''^day-0[1-7]$'')]
  [string]$Day,
  [string]$GateRoot = ''docs/decoupling/gates/g0/window-2026-04-17''
)

$ErrorActionPreference = ''Stop''
$DayDir = Join-Path $GateRoot $Day
$AuditDir = Join-Path $DayDir ''audit''
New-Item -ItemType Directory -Force -Path $DayDir, $AuditDir | Out-Null

(git rev-parse HEAD).Trim() | Set-Content -Encoding UTF8 (Join-Path $DayDir ''commit_sha.txt'')
php -v | Set-Content -Encoding UTF8 (Join-Path $DayDir ''php_version.txt'')
(Get-Date).ToUniversalTime().ToString(''o'') | Set-Content -Encoding UTF8 (Join-Path $DayDir ''captured_at_utc.txt'')
php -r "require_once 'scripts/optimization/AgenticOptimization.php'; echo AgenticOptimizationCoordinator::resolveRejectAuditMode([]), PHP_EOL;" | Set-Content -Encoding UTF8 (Join-Path $DayDir ''mode_resolution.txt'')

$env:G0_DAY_DIR = (Resolve-Path $DayDir).Path
$env:G0_AUDIT_DIR = (Resolve-Path $AuditDir).Path
$phpScript = @''
<?php
declare(strict_types=1);
$repoRoot = getcwd();
require_once $repoRoot . '/scripts/optimization/AgenticOptimization.php';
$dayDir = getenv('G0_DAY_DIR');
$auditDir = getenv('G0_AUDIT_DIR');
$baselinePath = $repoRoot . '/tests/fixtures/agentic_reject_audit/phase0_legacy_baseline.json';
$legacyAudit = AgenticRejectedIterationAuditor::run($repoRoot, $auditDir);
$baseline = json_decode((string)file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
$current = [
  'audited_events_count' => (int)($legacyAudit['audited_events_count'] ?? 0),
  'audited_events' => array_values((array)($legacyAudit['audited_events'] ?? [])),
  'flag_histogram' => (array)($legacyAudit['flag_histogram'] ?? []),
  'key_failure_patterns' => array_values((array)($legacyAudit['key_failure_patterns'] ?? [])),
  'rejected_iteration_audit' => [
    'audited_events_count' => (int)($legacyAudit['audited_events_count'] ?? 0),
    'key_failure_patterns' => array_values((array)($legacyAudit['key_failure_patterns'] ?? [])),
    'flag_histogram' => (array)($legacyAudit['flag_histogram'] ?? []),
  ],
];
file_put_contents($dayDir . '/legacy_projection.json', json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$expected = [
  'audited_events_count' => (int)($baseline['core_fields']['audited_events_count'] ?? 0),
  'audited_events' => array_values((array)($baseline['core_fields']['audited_events'] ?? [])),
  'flag_histogram' => (array)($baseline['core_fields']['flag_histogram'] ?? []),
  'key_failure_patterns' => array_values((array)($baseline['core_fields']['key_failure_patterns'] ?? [])),
  'rejected_iteration_audit' => (array)($baseline['derived']['rejected_iteration_audit'] ?? []),
];
$mismatches = [];
foreach (['audited_events_count','audited_events','flag_histogram','key_failure_patterns'] as $f) {
  if (($current[$f] ?? null) !== ($expected[$f] ?? null)) { $mismatches[] = $f; }
}
if (($current['rejected_iteration_audit'] ?? null) !== ($expected['rejected_iteration_audit'] ?? null)) { $mismatches[] = 'rejected_iteration_audit'; }
$diagFiles = [
  'rejected_iteration_shadow_parity.json',
  'rejected_iteration_manifest_preferred_diagnostic.json',
  'rejected_iteration_manifest_strict_diagnostic.json',
];
$diagFound = [];
foreach ($diagFiles as $f) { $diagFound[$f] = file_exists($auditDir . DIRECTORY_SEPARATOR . $f); }
$report = [
  'mode' => trim((string)file_get_contents($dayDir . '/mode_resolution.txt')),
  'parity_pass' => count($mismatches) === 0,
  'parity_mismatches' => $mismatches,
  'diagnostics_absent_pass' => !in_array(true, $diagFound, true),
  'diagnostics_found' => $diagFound,
];
file_put_contents($dayDir . '/parity_result.json', json_encode(['pass' => $report['parity_pass'], 'mismatches' => $mismatches], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($dayDir . '/diagnostics_absence_check.json', json_encode(['pass' => $report['diagnostics_absent_pass'], 'files' => $diagFound], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($report['mode'] !== 'legacy' || !$report['parity_pass'] || !$report['diagnostics_absent_pass']) {
  fwrite(STDERR, "G0_DAY_FAIL\n");
  exit(1);
}
echo "G0_DAY_PASS\n";
?>
''@
$tmp = Join-Path (Get-Location) ''.tmp_g0_day_runner.php''
Set-Content -Path $tmp -Value $phpScript -NoNewline
php $tmp | Set-Content -Encoding UTF8 (Join-Path $DayDir ''run_stdout.txt'')
$code = $LASTEXITCODE
Remove-Item -LiteralPath $tmp -Force
if ($code -ne 0) { throw ''WINDOW_RESET_REQUIRED: daily checks failed.'' }

@(
  'simulation_output/current-db/verification-v2/verification_summary_v2.json',
  'simulation_output/current-db/comparisons-v3-fast/comparison_tuning-verify-v3-fast-1.json'
) | ForEach-Object {
  $h = Get-FileHash $_ -Algorithm SHA256
  [pscustomobject]@{ path = $_; sha256 = $h.Hash.ToLowerInvariant() }
} | ConvertTo-Json -Depth 6 | Set-Content -Encoding UTF8 (Join-Path $DayDir ''source_checksums.json'')

if ($Day -ne 'day-01') {
  $refDir = Join-Path $GateRoot 'day-01'
  $refCommit = (Get-Content (Join-Path $refDir 'commit_sha.txt') -Raw).Trim()
  $dayCommit = (Get-Content (Join-Path $DayDir 'commit_sha.txt') -Raw).Trim()
  if ($refCommit -ne $dayCommit) { throw 'WINDOW_RESET_REQUIRED: commit changed vs day-01.' }
  $refPhp = (Get-Content (Join-Path $refDir 'php_version.txt') -Raw).Trim()
  $dayPhp = (Get-Content (Join-Path $DayDir 'php_version.txt') -Raw).Trim()
  if ($refPhp -ne $dayPhp) { throw 'WINDOW_RESET_REQUIRED: runtime changed vs day-01.' }
}

'PASS' | Set-Content -Encoding UTF8 (Join-Path $DayDir 'daily_status.txt')
Write-Host "Gate G0 $Day PASS"
