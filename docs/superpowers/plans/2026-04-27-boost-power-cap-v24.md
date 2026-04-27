# Boost Power Cap v2.4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce the existing 100% per-product boost power cap for one active SELF boost session while preserving the 500% combined total cap.

**Architecture:** `BoostCatalog` remains the source of truth for cap constants. Runtime action execution, simulator purchase behavior, and runtime parity certification all project active boost power with `POWER_CAP_FP_PER_PRODUCT`, then keep the existing `TOTAL_POWER_CAP_FP` combined guard.

**Tech Stack:** PHP 8, PHPUnit, existing simulation scripts, canonical simulation effective-config preflight.

---

### Task 1: Red Tests

**Files:**
- Modify: `tests/BoostCatalogRuntimeTest.php`
- Modify: `tests/BoostUptimeGovernorTest.php`
- Modify: `tests/SimulationConfigPreflightTest.php`

- [x] **Step 1: Add cap distinction assertions**

Add assertions that `BoostCatalog::POWER_CAP_FP_PER_PRODUCT` is `1000000` and `BoostCatalog::TOTAL_POWER_CAP_FP` is `5000000`.

- [x] **Step 2: Add simulator red test**

Add a test that gives a simulated player two Tier 5 sigils, activates a Tier 5 power boost, then attempts a second power purchase. Expected behavior: modifier remains `1000000`, second sigil is not spent, and boost spend count remains `1`.

- [x] **Step 3: Add runtime parity red test**

Add the same Tier 5 double-power scenario through `RuntimeParityCertification::runtimePurchaseBoost()`. Expected behavior: modifier remains `1000000` and second sigil remains available.

- [x] **Step 4: Add preflight red test**

Add an assertion that `boost_power_cap_fp_per_product` appears in the runtime effective-config audit with `BoostCatalog::POWER_CAP_FP_PER_PRODUCT`.

- [x] **Step 5: Verify red**

Run:

```powershell
php vendor\bin\phpunit tests\BoostCatalogRuntimeTest.php tests\BoostUptimeGovernorTest.php --filter "PowerCap|perProduct|CannotStack" --no-coverage
php vendor\bin\phpunit tests\SimulationConfigPreflightTest.php --filter RuntimeAuditIncludesSigilScarcityControls --no-coverage
```

Expected: the new simulator/parity tests fail because current code allows 200% on one active SELF boost.

### Task 2: Runtime And Simulator Cap Enforcement

**Files:**
- Modify: `includes/actions.php`
- Modify: `scripts/simulation/SimulationPlayer.php`
- Modify: `scripts/simulation/RuntimeParityCertification.php`
- Modify: `scripts/simulation/SimulationConfigPreflight.php`

- [x] **Step 1: Use product cap in runtime action projection**

In `Actions::purchaseBoost()`, introduce `$productPowerCapFp = BoostCatalog::POWER_CAP_FP_PER_PRODUCT`. For active power purchases, project the active row with `$productPowerCapFp` and keep `$totalPowerCapFp` only for the combined active boost guard.

- [x] **Step 2: Return product cap metadata**

Keep `total_power_cap_fp` as `BoostCatalog::TOTAL_POWER_CAP_FP`, but return `power_cap_fp` as `BoostCatalog::POWER_CAP_FP_PER_PRODUCT`.

- [x] **Step 3: Use product cap in simulator**

In `SimulationPlayer::purchaseBoost()`, project active power purchases with `BoostCatalog::POWER_CAP_FP_PER_PRODUCT`.

- [x] **Step 4: Use product cap in parity certification**

In `RuntimeParityCertification::runtimePurchaseBoost()`, project active power purchases with `BoostCatalog::POWER_CAP_FP_PER_PRODUCT`.

- [x] **Step 5: Add product cap to preflight runtime audit**

Add `boost_power_cap_fp_per_product` to `SimulationConfigPreflight` runtime metadata and resolve it from `BoostCatalog::POWER_CAP_FP_PER_PRODUCT`.

- [x] **Step 6: Verify green**

Run:

```powershell
php vendor\bin\phpunit tests\BoostCatalogRuntimeTest.php tests\BoostUptimeGovernorTest.php --no-coverage
php vendor\bin\phpunit tests\SimulationConfigPreflightTest.php --filter RuntimeAuditIncludesSigilScarcityControls --no-coverage
```

Expected: all tests pass.

### Task 3: Focused Verification And Simulation

**Files:**
- Read: `simulation_output/current-db/export/current_season_economy_only.json`
- Create: `simulation_output/current-db/boost-power-cap-v24-batch-20260427-snapshot/`
- Create: `simulation_output/current-db/boost-power-cap-v24-diagnosis-20260427-snapshot/`

- [x] **Step 1: Run focused test suite**

Run:

```powershell
php vendor\bin\phpunit tests\BoostCatalogRuntimeTest.php tests\BoostUptimeGovernorTest.php tests\SigilTheftLogicTest.php tests\SigilAbilityFlowTest.php tests\PolicyBehaviorUtilityFlowTest.php tests\EconomyPrecisionTest.php tests\SimulationConfigPreflightTest.php tests\RuntimeParityCertificationTest.php tests\SimulationPolicySweepSmokeTest.php --no-coverage
```

Expected: tests pass, allowing the existing PHPUnit deprecation if it remains from the reflection harness.

- [x] **Step 2: Run canonical simulation batch**

Run:

```powershell
php scripts\run_baseline_batch.php --season-config=simulation_output\current-db\export\current_season_economy_only.json --output=simulation_output\current-db\boost-power-cap-v24-batch-20260427-snapshot
```

Expected: batch manifest is valid and the run generates `effective_config.json` and `effective_config_audit.md`.

- [x] **Step 3: Analyze and diagnose**

Run:

```powershell
php scripts\analyze_baseline.php --manifest=simulation_output\current-db\boost-power-cap-v24-batch-20260427-snapshot\batch_manifest.json
php scripts\diagnose_economy.php --report=simulation_output\current-db\boost-power-cap-v24-batch-20260427-snapshot\baseline_analysis_report.json --output=simulation_output\current-db\boost-power-cap-v24-diagnosis-20260427-snapshot
```

Expected: diagnosis reports whether boost share falls enough for a greenlight or whether v2.5 should tune sigil supply/action flow.

Outcome: canonical batch completed 21/21 with 0 failures and generated required effective-config artifacts. Diagnosis still reports boost dominance at 67.9% of top-quartile coin delta, so v2.4 verifies behavior but points v2.5 toward sigil supply/action-flow tuning rather than more per-row boost power work.

### Task 4: Checkpoint

**Files:**
- Review changed source, tests, docs.

- [x] **Step 1: Run syntax and whitespace checks**

Run:

```powershell
php -l includes\actions.php
php -l scripts\simulation\SimulationPlayer.php
php -l scripts\simulation\RuntimeParityCertification.php
git diff --check
```

Expected: no syntax errors or whitespace errors.

- [x] **Step 2: Commit if verified**

Run:

```powershell
git add docs/superpowers/specs/2026-04-27-boost-power-cap-v24-design.md docs/superpowers/plans/2026-04-27-boost-power-cap-v24.md includes/actions.php scripts/simulation/SimulationPlayer.php scripts/simulation/RuntimeParityCertification.php scripts/simulation/SimulationConfigPreflight.php tests/BoostCatalogRuntimeTest.php tests/BoostUptimeGovernorTest.php tests/SimulationConfigPreflightTest.php
git commit -m "Enforce per-product boost power cap"
```

Expected: source/dev has a committed v2.4 checkpoint ready for test-lane promotion only after simulation diagnosis is acceptable.
