# Tactical Boost Economy v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make boost activation tactical by enforcing the 12-hour cap on initial activation and reducing canonical sigil drop throughput to a 1.0% base gate.

**Architecture:** `BoostCatalog` remains the shared source of truth for runtime, API preview, simulation, and parity certification. `SIGIL_DROP_CHANCE_FP` remains a canonical config constant surfaced through `SimulationConfigPreflight`.

**Tech Stack:** PHP 8, PHPUnit, existing simulation preflight and diagnosis tools.

---

### Task 1: Red Tests

**Files:**
- Modify: `tests/BoostCatalogRuntimeTest.php`
- Modify: `tests/EconomyPrecisionTest.php`

- [x] **Step 1: Add boost cap tests**

Add tests proving Tier 1 initial duration and normalized catalog duration cannot exceed `BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT`.

- [x] **Step 2: Add drop-rate test**

Add a test proving the canonical base sigil gate is `10000` fixed-point units and that active players with no pressure receive that base chance.

- [x] **Step 3: Run red tests**

Run:

```powershell
php vendor\bin\phpunit tests\BoostCatalogRuntimeTest.php tests\EconomyPrecisionTest.php --no-coverage
```

Expected: failures showing Tier 1 still returns 24 hours and `SIGIL_DROP_CHANCE_FP` is still `125000`.

### Task 2: Implementation

**Files:**
- Modify: `includes/boost_catalog.php`
- Modify: `includes/config.php`

- [x] **Step 1: Cap initial boost duration**

In `BoostCatalog::getInitialDurationRealSecondsForTier()`, return the lower of the tier initial duration and `TIME_CAP_SECONDS_PER_PRODUCT`.

- [x] **Step 2: Cap normalized catalog duration**

In `BoostCatalog::normalize()`, set `duration_real_seconds` and `duration_ticks` from the effective capped initial duration for the tier.

- [x] **Step 3: Tune base sigil gate**

Change `SIGIL_DROP_CHANCE_FP` to `10000` and update the nearby comment to `1.0% base gate chance`.

- [x] **Step 4: Run green tests**

Run:

```powershell
php vendor\bin\phpunit tests\BoostCatalogRuntimeTest.php tests\EconomyPrecisionTest.php --no-coverage
```

Expected: all tests pass.

### Task 3: Parity And Config Verification

**Files:**
- Read-only verification unless tests expose a regression.

- [x] **Step 1: Syntax check modified PHP**

Run:

```powershell
php -l includes\boost_catalog.php
php -l includes\config.php
```

Expected: `No syntax errors detected`.

- [x] **Step 2: Run focused parity/preflight tests**

Run:

```powershell
php vendor\bin\phpunit tests\BoostCatalogRuntimeTest.php tests\EconomyPrecisionTest.php tests\SimulationConfigPreflightTest.php tests\RuntimeParityCertificationTest.php --no-coverage
```

Expected: all tests pass.

### Task 4: Simulation Gate

**Files:**
- Output: `simulation_output/current-db/boost-scarcity-v2-batch-20260427-snapshot`
- Output: `simulation_output/current-db/boost-scarcity-v2-diagnosis-20260427-snapshot`

- [x] **Step 1: Run full canonical batch**

Run:

```powershell
php scripts/run_baseline_batch.php --season-config=simulation_output/current-db/export/current_season_economy_only.json --output=simulation_output/current-db/boost-scarcity-v2-batch-20260427-snapshot
```

Expected: batch finishes with zero failed runs and each run emits `effective_config.json` and `effective_config_audit.md`.

- [x] **Step 2: Analyze batch**

Run:

```powershell
php scripts/analyze_baseline.php --manifest=simulation_output/current-db/boost-scarcity-v2-batch-20260427-snapshot/batch_manifest.json --output=simulation_output/current-db/boost-scarcity-v2-batch-20260427-snapshot
```

Expected: `baseline_analysis_report.json` and `baseline_summary.md` are generated.

- [x] **Step 3: Diagnose economy**

Run:

```powershell
php scripts/diagnose_economy.php --report=simulation_output/current-db/boost-scarcity-v2-batch-20260427-snapshot/baseline_analysis_report.json --output=simulation_output/current-db/boost-scarcity-v2-diagnosis-20260427-snapshot
```

Expected: `diagnosis_report.json` and `diagnosis_summary.md` are generated.

- [x] **Step 4: Promotion decision**

Compare V2 against V1. If boost dominance and sigil overabundance materially improve without a major lock-in/expiry regression, recommend transfer to main/test for playtesting. Otherwise keep iterating in `source/dev`.

V2 result: strong improvement, but not a full playtest greenlight. High severity findings dropped from `1` to `0`, top-quartile boost delta dropped from `100.4%` to `0.0%`, and median total sigils dropped from `389.1` to `71.9`. Remaining blockers are high per-archetype boost coin share, underused freeze/theft, near-zero blackout actions, and likely overcorrection against boost-focused lock-in.
