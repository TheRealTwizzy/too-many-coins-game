# Idle Viability v2.6 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clear the remaining mostly-idle viability HIGH finding without re-opening boost dominance.

**Architecture:** Treat the idle-factor change as a canonical season candidate patch for validation and as a minimal season-config promotion for play-testing. Add candidate patch file support to the existing simulation CLIs so every validation run records the active change in the effective-config audit.

**Tech Stack:** PHP 8, PHPUnit, existing canonical simulation scripts.

---

### Task 1: Red Tests

**Files:**
- Create: `tests/SimulationCandidatePatchCliTest.php`
- Modify: `tests/SimulationConfigPreflightTest.php`
- Modify: `tests/SimulationExportImportTest.php`

- [x] **Step 1: Add CLI candidate patch audit test**

Create a temp candidate patch JSON containing:

```json
{
  "base_ubi_idle_factor_fp": 300000
}
```

Run:

```powershell
php scripts\simulate_contracts.php --seed=cli-candidate-patch --candidate-patch=<temp-json> --output=<temp-dir>
```

Assert the generated `effective_config.json` contains one requested candidate change with `path = season.base_ubi_idle_factor_fp`, `effective_value = 300000`, `effective_source = candidate_patch`, and `is_active = true`.

- [x] **Step 2: Add batch dry-run missing-patch rejection test**

Run:

```powershell
php scripts\run_baseline_batch.php --season-config=simulation_output\current-db\export\current_season_economy_only.json --candidate-patch=<missing-json> --output=<temp-dir> --dry-run
```

Assert exit code is non-zero and output contains `Candidate patch not found`.

- [x] **Step 3: Add preflight expectation**

Assert `SimulationConfigPreflight::resolve()` accepts `candidate_patch => ['base_ubi_idle_factor_fp' => 300000]` and reports the candidate value as active.

- [x] **Step 4: Add default-season expectation**

Assert `SimulationSeason::build()` returns `base_ubi_idle_factor_fp = 300000`.

- [x] **Step 5: Verify red**

Run:

```powershell
php vendor\bin\phpunit tests\SimulationCandidatePatchCliTest.php tests\SimulationConfigPreflightTest.php tests\SimulationExportImportTest.php --filter "CandidatePatch|IdleViability|DefaultSeason" --no-coverage
```

Expected: failures because CLI candidate-patch support and default idle factor are not implemented yet.

### Task 2: Implement Candidate Patch CLI Support

**Files:**
- Modify: `scripts/simulate_contracts.php`
- Modify: `scripts/simulate_economy.php`
- Modify: `scripts/simulate_lifetime.php`
- Modify: `scripts/run_baseline_batch.php`
- Modify: `scripts/simulation/ContractSimulator.php`

- [x] **Step 1: Parse candidate patch file**

Add `--candidate-patch=FILE` to the three simulator CLIs and decode it as JSON object/array. Missing files or invalid JSON must exit non-zero before simulation starts.

- [x] **Step 2: Pass patch through preflight**

Pass decoded candidate patch into `SimulationPopulationSeason::run()`, `SimulationPopulationLifetime::run()`, and `ContractSimulator::run()`.

- [x] **Step 3: Wire batch runner**

Add `--candidate-patch=FILE` to `run_baseline_batch.php`, validate it once, and append it to contract, Sim B, and Sim C args.

- [x] **Step 4: Verify green**

Run:

```powershell
php vendor\bin\phpunit tests\SimulationCandidatePatchCliTest.php tests\SimulationConfigPreflightTest.php tests\SimulationExportImportTest.php --filter "CandidatePatch|IdleViability|DefaultSeason" --no-coverage
```

Expected: CLI tests pass once Task 3 default tuning is implemented.

### Task 3: Implement Idle Viability Tune

**Files:**
- Modify: `scripts/simulation/SimulationSeason.php`
- Modify: `includes/game_time.php`
- Modify: `schema.sql`
- Create: `migration_20260427_idle_viability_v26.sql`

- [x] **Step 1: Update simulation default**

Set `SimulationSeason::build()` default `base_ubi_idle_factor_fp` to `300000`.

- [x] **Step 2: Update new runtime seasons**

Set the explicit `base_ubi_idle_factor_fp` insert value in `GameTime::ensureSeasons()` to `300000`.

- [x] **Step 3: Update legacy rebalance path**

Set `base_ubi_idle_factor_fp = 300000` in `GameTime::rebalanceExistingSeasons()` when legacy seasons are upgraded to the current economy.

- [x] **Step 4: Update schema default**

Set the `schema.sql` default to `300000`.

- [x] **Step 5: Add play-test migration**

Create a minimal, idempotent migration:

```sql
UPDATE seasons
SET base_ubi_idle_factor_fp = 300000
WHERE status IN ('Scheduled', 'Active', 'Blackout')
  AND base_ubi_active_per_tick = 30
  AND base_ubi_idle_factor_fp = 250000;
```

Do not reset players or runtime gameplay state.

### Task 4: Greenlight Validation

**Files:**
- Create generated candidate patch under `simulation_output/current-db/idle-viability-v26-candidate.patch.json`
- Create generated v2.6 simulation output directories

- [x] **Step 1: Run smoke**

Run:

```powershell
php scripts\simulate_economy.php --seed=idle-viability-v26-smoke --players-per-archetype=10 --season-config=simulation_output\current-db\export\current_season_economy_only.json --candidate-patch=simulation_output\current-db\idle-viability-v26-candidate.patch.json --output=simulation_output\current-db\idle-viability-v26-smoke-20260427-snapshot
```

Expected: simulation succeeds, audit artifacts exist, and `base_ubi_idle_factor_fp` is active from candidate patch.

Observed: the final 30% smoke passed with `base_ubi_idle_factor_fp = 300000` active from `candidate_patch`.

- [x] **Step 2: Run canonical batch**

Run:

```powershell
php scripts\run_baseline_batch.php --season-config=simulation_output\current-db\export\current_season_economy_only.json --candidate-patch=simulation_output\current-db\idle-viability-v26-candidate.patch.json --output=simulation_output\current-db\idle-viability-v26-batch-20260427-snapshot
```

Expected: 21 completed, 0 failed, all audits generated.

Observed: 21 completed, 0 skipped, 0 failed, 22 audit directories, and no missing effective-config artifacts.

- [x] **Step 3: Analyze and diagnose**

Run:

```powershell
php scripts\analyze_baseline.php --manifest=simulation_output\current-db\idle-viability-v26-batch-20260427-snapshot\batch_manifest.json
php scripts\diagnose_economy.php --report=simulation_output\current-db\idle-viability-v26-batch-20260427-snapshot\baseline_analysis_report.json --output=simulation_output\current-db\idle-viability-v26-diagnosis-20260427-snapshot
```

Expected: no HIGH findings and boost top-quartile coin delta share remains below 40%.

Observed: 0 HIGH findings, mostly-idle ratio `0.51`, and boost top-quartile coin delta share `37.6%`.

### Task 5: Commit, Push, And Playtest Merge

**Files:**
- Source/test/docs/migration only. Do not stage generated simulation output.

- [x] **Step 1: Final verification**

Run syntax, focused PHPUnit suite, `git diff --check`, and inspect status.

Observed: syntax checks passed, `git diff --check` passed, and the focused suite passed (`141 tests`, `2652 assertions`, one existing deprecation).

- [ ] **Step 2: Commit and push source/dev**

Commit only source/test/docs/migration files.

- [ ] **Step 3: Merge to main for playtesting**

After `source/dev` push succeeds, merge into `main`, push `main`, and return to `source/dev`.
