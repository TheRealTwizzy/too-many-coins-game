# Cleanup Validation Status

## Batch `2026-04-16-batch-001`

| Check | Command | Status (PASS/FAIL/PENDING) | Notes |
|---|---|---|---|
| Git status snapshot | `git status --short` | PASS | Executed during immediate gate run. |
| Git diff name-status | `git diff --name-status HEAD` | PASS | Executed during immediate gate run. |
| Manifest exists + schema keys | `Test-Path tmp/_quarantine/2026-04-16-batch-001/quarantine_manifest.json` and JSON key check (`original_path,new_path,rationale,risk_tier,reference_check,restore_instructions`) | PASS | Manifest present; 23 entries; required keys validated. |
| Originals removed from source paths | `Get-ChildItem tmp -File -Filter 'candidate_*.json'` and path checks for `tmp/show_findings.php`, `tmp/strip_timing.php` | PASS | No originals remain at source paths. |
| Quarantined targets present | `Test-Path <new_path>` for each manifest entry | PASS | All 23 moved targets exist in quarantine payload path. |
| Cache deletion check | `Test-Path .phpunit.result.cache` (expect false) | PASS | Cache file absent after batch execution. |
| Ignore coverage check | `rg -n -F '.phpunit.result.cache' .gitignore` and `rg -n -F 'artifacts/optimization/latest_agent_results' .gitignore` | PASS | Both ignore entries present. |
| Protected-path drift check | `git diff --name-only HEAD` against protected path regex allowlist | PASS | No protected path modified in this batch. |
| Live reference sanity | `rg -n -F "<each original batch filename>" api includes scripts tools tests docker public README.md SIMULATION_RUNBOOK.md` (expect no hits) | PASS | Runtime/deploy surfaces have no references to moved filenames. Initial broad regex hit only generic `tmp/candidate_patch.json` and was excluded as out-of-scope. |

## Optional Lightweight Follow-up

- `composer test -- --filter SimulationConfigPreflightTest`
- `node tests/leaderboard_status_test.js`

## Batch `2026-04-16-batch-002`

| Check | Command | Status (PASS/FAIL/PENDING) | Notes |
|---|---|---|---|
| Git status snapshot | `git status --short` | PASS | Executed during immediate gate run. |
| Git diff name-status | `git diff --name-status HEAD` | PASS | Quarantine-only delta verified. |
| Manifest exists + required fields | `Test-Path tmp/_quarantine/2026-04-16-batch-002/quarantine_manifest.json` and JSON key checks | PASS | Manifest present; 4 entries; required fields validated. |
| Source paths absent | `Test-Path tmp/full-campaign-proof-20260414b`, `Get-ChildItem tmp -Directory -Filter qualification-20260414*`, `Test-Path tmp/q14` | PASS | All source directories absent after move. |
| Quarantine destinations present | `Test-Path tmp/_quarantine/2026-04-16-batch-002/payload/tmp/<dir>` | PASS | All four destination directories present. |
| Protected-path drift check | Compare changed files against protected path regex allowlist | PASS | No protected paths touched. |
| simulation_output drift check | `git diff --name-only HEAD \| rg '^simulation_output/'` | PASS | No `simulation_output/` paths changed in batch-002. |
