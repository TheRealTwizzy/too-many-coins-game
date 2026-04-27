# Boost Recovery And Sigil Throttle v2.5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce sustainable boost uptime and sigil fuel enough to clear the high-severity boost-dominance diagnosis.

**Architecture:** Keep the existing runtime/simulation architecture. `BoostCatalog` remains the source of truth for boost recovery, while `includes/config.php` remains the source of truth for sigil drop chance and boost-drop pressure. `SimulationConfigPreflight` already exposes these values, so tests should verify the audit reflects the new constants.

**Tech Stack:** PHP 8, PHPUnit, existing canonical simulation scripts.

---

### Task 1: Red Tests

**Files:**
- Modify: `tests/BoostUptimeGovernorTest.php`
- Modify: `tests/EconomyPrecisionTest.php`
- Modify: `tests/SimulationConfigPreflightTest.php`

- [x] **Step 1: Add recovery expectation**

Assert that `BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION` is 12 hours and that recovery ticks match the configured tick cadence.

- [x] **Step 2: Add sigil base gate expectation**

Assert that `SIGIL_DROP_CHANCE_FP` is `3500`.

- [x] **Step 3: Add boosted pressure floor expectation**

Assert that `SIGIL_BOOST_DROP_PRESSURE_MIN_FP` is `100000` and that a 100% boost uses that floor.

- [x] **Step 4: Add preflight audit expectations**

Assert that runtime audit reports:

- `sigil_drop_chance_fp = 3500`
- `sigil_boost_drop_pressure_min_fp = 100000`
- `boost_recovery_seconds_after_session = 43200`

- [x] **Step 5: Verify red**

Run:

```powershell
php vendor\bin\phpunit tests\BoostUptimeGovernorTest.php tests\EconomyPrecisionTest.php tests\SimulationConfigPreflightTest.php --filter "Recovery|base|pressure|RuntimeAudit" --no-coverage
```

Expected: failures showing the current values are still 4h recovery, `10000` base chance, and `250000` boosted floor.

### Task 2: Runtime Tuning

**Files:**
- Modify: `includes/boost_catalog.php`
- Modify: `includes/config.php`

- [x] **Step 1: Increase recovery**

Change `BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION` from `4 * 60 * 60` to `12 * 60 * 60`.

- [x] **Step 2: Lower base sigil gate**

Change `SIGIL_DROP_CHANCE_FP` from `10000` to `3500` and update the adjacent comment to `0.35% base gate chance`.

- [x] **Step 3: Tighten boosted drop floor**

Change `SIGIL_BOOST_DROP_PRESSURE_MIN_FP` from `250000` to `100000` and update the comment to state 10%.

- [x] **Step 4: Verify green**

Run:

```powershell
php vendor\bin\phpunit tests\BoostUptimeGovernorTest.php tests\EconomyPrecisionTest.php tests\SimulationConfigPreflightTest.php --filter "Recovery|base|pressure|RuntimeAudit" --no-coverage
```

Expected: focused tests pass.

### Task 3: Fast Screening

**Files:**
- Create: `simulation_output/current-db/boost-recovery-sigil-throttle-v25-smoke-20260427-snapshot/`

- [x] **Step 1: Run focused single-season smoke**

Run a small canonical-audited season sim before spending the full batch time:

```powershell
php scripts\simulate_economy.php --seed=boost-recovery-sigil-throttle-v25-smoke --players-per-archetype=10 --season-config=simulation_output\current-db\export\current_season_economy_only.json --output=simulation_output\current-db\boost-recovery-sigil-throttle-v25-smoke-20260427-snapshot
```

Expected: simulation succeeds and writes audit artifacts.

- [x] **Step 2: Check smoke metrics**

Read the generated JSON for boost-focused/regular score ratio, boosted ticks, and sigil acquisition. If the smoke is obviously worse than v2.4, revise before full batch.

### Task 4: Greenlight Batch

**Files:**
- Create: `simulation_output/current-db/boost-recovery-sigil-throttle-v25-batch-20260427-snapshot/`
- Create: `simulation_output/current-db/boost-recovery-sigil-throttle-v25-diagnosis-20260427-snapshot/`

- [ ] **Step 1: Run canonical batch**

Run:

```powershell
php scripts\run_baseline_batch.php --season-config=simulation_output\current-db\export\current_season_economy_only.json --output=simulation_output\current-db\boost-recovery-sigil-throttle-v25-batch-20260427-snapshot
```

Expected: 21 completed, 0 failed, all audit artifacts generated.

- [ ] **Step 2: Analyze and diagnose**

Run:

```powershell
php scripts\analyze_baseline.php --manifest=simulation_output\current-db\boost-recovery-sigil-throttle-v25-batch-20260427-snapshot\batch_manifest.json
php scripts\diagnose_economy.php --report=simulation_output\current-db\boost-recovery-sigil-throttle-v25-batch-20260427-snapshot\baseline_analysis_report.json --output=simulation_output\current-db\boost-recovery-sigil-throttle-v25-diagnosis-20260427-snapshot
```

Expected: no HIGH findings and boost top-quartile coin delta share below 40%.

### Task 5: Commit, Push, And Playtest Merge

**Files:**
- Source/test/docs only. Do not stage generated simulation output.

- [ ] **Step 1: Final verification**

Run:

```powershell
php -l includes\config.php
php -l includes\boost_catalog.php
php vendor\bin\phpunit tests\BoostCatalogRuntimeTest.php tests\BoostUptimeGovernorTest.php tests\SigilTheftLogicTest.php tests\SigilAbilityFlowTest.php tests\PolicyBehaviorUtilityFlowTest.php tests\EconomyPrecisionTest.php tests\SimulationConfigPreflightTest.php tests\RuntimeParityCertificationTest.php tests\SimulationPolicySweepSmokeTest.php --no-coverage
git diff --check
```

Expected: syntax passes, focused suite passes, whitespace check is clean.

- [ ] **Step 2: Commit and push source/dev**

Commit only source/test/docs files with:

```powershell
git add docs/superpowers/specs/2026-04-27-boost-recovery-sigil-throttle-v25-design.md docs/superpowers/plans/2026-04-27-boost-recovery-sigil-throttle-v25.md includes/config.php includes/boost_catalog.php tests/BoostUptimeGovernorTest.php tests/EconomyPrecisionTest.php tests/SimulationConfigPreflightTest.php
git commit -m "Tune boost recovery and sigil throttle"
git push origin source/dev
```

- [ ] **Step 3: Merge to main for playtesting**

After `source/dev` push succeeds and the working tree has no tracked changes, merge into `main` without touching generated output:

```powershell
git switch main
git merge source/dev
git push origin main
git switch source/dev
```

Expected: `main` contains the greenlit source/dev commit for public playtesting, and the local workspace returns to `source/dev`.
