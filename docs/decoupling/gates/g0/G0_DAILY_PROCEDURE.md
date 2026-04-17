# Gate G0 Daily Procedure (Days 02-07)

Run from repo root once per day, changing only `DAY`.

## Variables
- `GATE_ROOT=docs/decoupling/gates/g0/window-2026-04-17`
- `DAY=day-02` (then day-03 ... day-07)
- `DAY_DIR=$GATE_ROOT/$DAY`

## Daily Commands
1. Create day folder and audit folder.
2. Capture metadata:
   - `git rev-parse HEAD > DAY_DIR/commit_sha.txt`
   - `php -v > DAY_DIR/php_version.txt`
   - UTC timestamp to `DAY_DIR/captured_at_utc.txt`
3. Verify no-flag mode resolution:
   - `php -r "require_once 'scripts/optimization/AgenticOptimization.php'; echo AgenticOptimizationCoordinator::resolveRejectAuditMode([]), PHP_EOL;" > DAY_DIR/mode_resolution.txt`
4. Run lightweight no-flag legacy audit path:
   - invoke `AgenticRejectedIterationAuditor::run(repoRoot, DAY_DIR/audit)` and write projection to `DAY_DIR/legacy_projection.json`.
5. Run zero-tolerance parity check against:
   - `tests/fixtures/agentic_reject_audit/phase0_legacy_baseline.json`
   - write result to `DAY_DIR/parity_result.json`.
6. Check non-default diagnostics absent in `DAY_DIR/audit`:
   - `rejected_iteration_shadow_parity.json`
   - `rejected_iteration_manifest_preferred_diagnostic.json`
   - `rejected_iteration_manifest_strict_diagnostic.json`
   - write result to `DAY_DIR/diagnostics_absence_check.json`.
7. Capture source checksums to `DAY_DIR/source_checksums.json`:
   - `simulation_output/current-db/verification-v2/verification_summary_v2.json`
   - `simulation_output/current-db/comparisons-v3-fast/comparison_tuning-verify-v3-fast-1.json`
8. Continuity checks versus Day 01:
   - commit SHA unchanged
   - PHP/runtime unchanged
   - source checksums unchanged
9. Write `DAY_DIR/daily_status.txt` with `PASS` or `FAIL`.

## Daily Pass Checks
- `mode_resolution.txt` equals `legacy`.
- `parity_result.json` has `pass=true` and no mismatches.
- `diagnostics_absence_check.json` has `pass=true`.
- Continuity checks against Day 01 all pass.

## Reset Conditions
- Commit changed from Day 01.
- Runtime changed from Day 01.
- Source checksums changed from Day 01.
- Any parity mismatch.
- Any non-default diagnostic appears in no-flag run.
- Missing evidence artifact for that day.

On any reset condition, mark the day `FAIL`, create `reset_reason.txt`, and restart a new 7-day window.


## Automation Helper
- Use [run_g0_day.ps1](C:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\docs\decoupling\gates\g0\templates\run_g0_day.ps1) for exact daily execution.
- Example: pwsh -File docs/decoupling/gates/g0/templates/run_g0_day.ps1 -Day day-02`n
