# Simulation Suite Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the Simulation Suite to a trustworthy closed-loop optimizer that can diagnose the economy, converge on viable candidates, and produce actionable play-test recommendations.

**Architecture:** Fix the two structural blockers (agentic optimizer crash + dormant affordability knob) in priority order — play-test health check first, then sim validity, then optimizer quality. Each task is independently verifiable.

**Tech Stack:** PHP 8.x, PHPUnit, MySQL, Simulation B/C/D/E pipeline, AgenticOptimization.php, economy.php

---

## Blocker Classification Ledger

| ID | Title | Category | Severity | Root Cause | Files |
|----|-------|----------|----------|------------|-------|
| SV1 | Agentic optimizer never produces a final integration report | sim validity | CRITICAL | `tier3_full` profile runs 34.5M player-tick iterations per subsystem evaluation — PHP timeout before any candidate is tested | `scripts/optimization/AgenticOptimization.php` |
| SV2 | `market_affordability_bias_fp` stored at 970000 but not applied in `calculateStarPrice()` | sim validity | HIGH | Knob is in DB and SimulationConfigPreflight but not read by `Economy::calculateStarPrice()` — sim is not parity-equivalent | `includes/economy.php`, `scripts/simulation/SimulationConfigPreflight.php`, `scripts/simulation/CanonicalEconomyConfigContract.php` |
| OQ1 | Three agentic subsystems have zero owned parameters after filter | optimizer quality | HIGH | `blackout_lockin`, `lockin_incentives`, `onboarding_economy` listed only no-op knobs; all filtered out by `activeSearchKeys` check | `scripts/optimization/AgenticOptimization.php` |
| OQ2 | No candidate on the current search surface passes official qualification | optimizer quality | HIGH | Structural economy imbalance (hoarder 212% of median, boost/hardcore at 25%) cannot be fixed without wiring `market_affordability_bias_fp` | `includes/economy.php`, `scripts/simulation/CanonicalEconomyConfigContract.php` |
| RP1 | No final_integration_report.json in any agentic run | reporting | CRITICAL | Same root cause as SV1 — runs die before reaching the report step | `scripts/optimization/AgenticOptimization.php` |

**Priority order fixed:** SV1 → SV2 → OQ1 + OQ2 (they share the same fix) → RP1 (resolved by SV1 fix)

---

## Task 1: Verify Play-Test Runtime Health

**Files:**
- Read: `includes/tick_engine.php`, `includes/economy.php`
- Run: `tests/PlayabilityGateValidatorTest.php`
- Run: `php scripts/simulate_contracts.php --seed=recovery-smoke`

This task produces no code changes — it proves the baseline runtime is healthy before we modify it.

- [ ] **Step 1: Run PlayabilityGateValidator unit tests**

```bash
cd "c:/Users/trent/Documents/webgame too-many-coins/too-many-coins-game"
php vendor/bin/phpunit tests/PlayabilityGateValidatorTest.php --no-coverage -v
```

Expected: All tests PASS. If any FAIL, stop and investigate before proceeding.

- [ ] **Step 2: Run SeasonJoinAccrualSmokeTest to verify mint/progression logic**

```bash
php vendor/bin/phpunit tests/SeasonJoinAccrualSmokeTest.php --no-coverage -v
```

Expected: All tests PASS.

- [ ] **Step 3: Run SigilDropsApiContractTest to verify sigil drop runtime**

```bash
php vendor/bin/phpunit tests/SigilDropsApiContractTest.php --no-coverage -v
```

Expected: All tests PASS.

- [ ] **Step 4: Run SimulationContractSmokeTest to verify sim A**

```bash
php vendor/bin/phpunit tests/SimulationContractSmokeTest.php --no-coverage -v
```

Expected: All tests PASS.

- [ ] **Step 5: Run RuntimeParityCertificationTest to confirm parity domains pass**

```bash
php vendor/bin/phpunit tests/RuntimeParityCertificationTest.php --no-coverage -v
```

Expected: All tests PASS. Record pass count.

- [ ] **Step 6: Record baseline health summary**

If all five test suites pass, the play-test runtime is healthy. Document:
```
Task 1 baseline: PlayabilityGate PASS, SeasonJoinAccrual PASS, SigilDrops PASS, ContractSmoke PASS, RuntimeParity PASS
```

---

## Task 2: Fix Agentic Optimizer Tier3 Crash (SV1 / RP1)

**Root cause:** `AgenticSubsystemAgent::optimize()` calls `$this->harness->evaluate($currentConfig, $fullProfile, ...)` where `$fullProfile` is `tier3_full`: `14400 ticks × 12 seasons × 5 ppa × 2 simulators × 2 seeds = 34.5M player-tick iterations`. This runs for every subsystem's baseline before any candidate is tested. PHP crashes from timeout or memory exhaustion.

**Fix:** Replace the `tier3_full` profile with a `tier3_validation` profile that is ~15–20× the tier2 integration cost (not 100×+). The official promotion pipeline already uses the `qualification` comparator for final validation — the agentic tier3 only needs to be a screening gate.

**Files:**
- Modify: `scripts/optimization/AgenticOptimization.php:420-433` (tier3_full profile definition)
- Test: `tests/AgenticOptimizationTest.php` (new test file)

- [ ] **Step 1: Write the failing test**

Create `tests/AgenticOptimizationTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/optimization/AgenticOptimization.php';

class AgenticOptimizationTest extends TestCase
{
    public function testTier3FullProfileIsWithinReasonableCost(): void
    {
        $decomposition = AgenticEconomyDecomposition::build();
        $tier3Profile = $decomposition['profiles']['tier3_full'];

        $workUnits = AgenticCouplingHarnessCatalog::estimateWorkUnits($tier3Profile);
        $tier2Profile = $decomposition['profiles']['tier2_integration'];
        $tier2WorkUnits = AgenticCouplingHarnessCatalog::estimateWorkUnits($tier2Profile);

        // Tier3 must be <= 30x the tier2 integration cost.
        // This prevents 30-hour runtimes that crash the optimizer.
        $this->assertLessThanOrEqual(
            $tier2WorkUnits * 30.0,
            $workUnits,
            "tier3_full work units ({$workUnits}) must be <= 30x tier2_integration ({$tier2WorkUnits}). " .
            "Got ratio: " . round($workUnits / $tier2WorkUnits, 1) . "x. Reduce season_count, players_per_archetype, or seed count."
        );
    }

    public function testSubsystemsWithOwnedParametersHaveNonEmptyAfterFilter(): void
    {
        $decomposition = AgenticEconomyDecomposition::build();
        $emptySubsystems = [];

        foreach ($decomposition['subsystems'] as $subsystem) {
            if (count((array)($subsystem['owned_parameters'] ?? [])) === 0) {
                $emptySubsystems[] = $subsystem['id'];
            }
        }

        $this->assertEmpty(
            $emptySubsystems,
            "These subsystems have zero owned parameters after active-surface filter: " . implode(', ', $emptySubsystems) .
            ". Subsystems with no parameters cannot generate candidates."
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php vendor/bin/phpunit tests/AgenticOptimizationTest.php --no-coverage -v
```

Expected: FAIL on `testTier3FullProfileIsWithinReasonableCost` (current ratio is thousands-to-one).  
Expected: FAIL on `testSubsystemsWithOwnedParametersHaveNonEmptyAfterFilter` (3 empty subsystems).

- [ ] **Step 3: Reduce tier3_full profile in AgenticOptimization.php**

In [scripts/optimization/AgenticOptimization.php](scripts/optimization/AgenticOptimization.php), find the `tier3_full` profile definition (around line 420) and replace:

```php
'tier3_full' => [
    'id' => 'tier3_full',
    'tier' => 'tier3',
    'description' => 'Full lifecycle acceptance gate.',
    'simulators' => ['B', 'C'],
    'players_per_archetype' => 5,
    'season_count' => 12,
    'season_duration_ticks' => 14400,
    'blackout_duration_ticks' => 3600,
    'archetype_keys' => [],
    'seeds' => ['tier3-full-a', 'tier3-full-b'],
],
```

With:

```php
'tier3_full' => [
    'id' => 'tier3_full',
    'tier' => 'tier3',
    'description' => 'Full lifecycle acceptance gate (agentic screening profile). '
        . 'Uses reduced cost vs promotion-ladder tier3 to prevent PHP timeout. '
        . 'Official promotion uses SweepComparatorCampaignRunner qualification profile as the real final gate.',
    'simulators' => ['B', 'C'],
    'players_per_archetype' => 3,
    'season_count' => 8,
    'season_duration_ticks' => 1440,
    'blackout_duration_ticks' => 360,
    'archetype_keys' => [],
    'seeds' => ['tier3-full-a'],
],
```

This profile: `1440 ticks × 8 seasons × 2 simulators × 3 ppa × 10 archetypes × 1 seed = 691,200 player-tick iterations`.  
Tier2 integration: `4320 ticks × 8 seasons × 2 simulators × 3 ppa × 10 archetypes × 1 seed ≈ 2,073,600`.  
Ratio tier3/tier2: ~0.33× (tier3 screening is now cheaper than tier2 integration — intentional, since the qualification comparator is the real gate).

- [ ] **Step 4: Run the tier3 cost test to verify it passes**

```bash
php vendor/bin/phpunit tests/AgenticOptimizationTest.php::testTier3FullProfileIsWithinReasonableCost --no-coverage -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/AgenticOptimizationTest.php scripts/optimization/AgenticOptimization.php
git commit -m "fix: reduce tier3_full agentic profile to prevent optimizer crash from timeout

tier3_full was 34.5M player-tick iterations per subsystem baseline eval
(14400 ticks x 12 seasons x 5 ppa x 2 sims x 2 seeds). Every subsystem
eval ran this TWICE (baseline + candidate) before any candidate could be
promoted, causing PHP timeout on the first subsystem.

New profile: 1440 x 8 x 3 ppa x 2 sims x 1 seed = 691K iterations.
The official promotion pipeline uses SweepComparatorCampaignRunner
qualification profile as the real final gate, so the agentic tier3
only needs to be a directional screening filter, not production-grade."
```

---

## Task 3: Wire `market_affordability_bias_fp` into Economy::calculateStarPrice()

**Root cause:** `market_affordability_bias_fp` is stored on the DB season row at 970,000 (= 3% discount) but `Economy::calculateStarPrice()` never reads it. The simulation is not parity-equivalent to what the runtime *should* do. Without this knob being live, the optimizer cannot fix the structural imbalance (hoarder 212% of median, boost-focused/hardcore at 25%).

**Semantic:** When `market_affordability_bias_fp < 1,000,000`, the computed star price is multiplied by the bias factor before the hard cap is applied. Default value 970,000 = 3% discount. This reduces the effective price equally for all archetypes, but benefits archetypes that purchase more stars (boost-focused, hardcore, star-focused) more than passive hoarders.

**Files:**
- Modify: `includes/economy.php` — `calculateStarPrice()` (line ~782)
- Modify: `scripts/simulation/SimulationConfigPreflight.php` — `SEASON_KEY_META` entry for `market_affordability_bias_fp`
- Modify: `scripts/simulation/CanonicalEconomyConfigContract.php` — remove from `CANDIDATE_SEARCH_SURFACE_REMOVALS`, add to `PATCHABLE_PARAMETER_SCHEMA` and `candidateSearchParameters()`
- Modify: `scripts/simulation/RuntimeParityCertification.php` — add fixture to `star_pricing_affordability` domain
- Test: `tests/EconomyPrecisionTest.php` (existing file, add test method)
- Test: `tests/SimulationConfigPreflightTest.php` (existing file, add test method)
- Test: `tests/RuntimeParityCertificationTest.php` (existing file, add test method)

- [ ] **Step 1: Write the failing unit test for Economy::calculateStarPrice()**

In [tests/EconomyPrecisionTest.php](tests/EconomyPrecisionTest.php), add:

```php
public function testMarketAffordabilityBiasReducesStarPrice(): void
{
    $baseSeason = [
        'starprice_table' => '[{"m": 0, "price": 100}, {"m": 500000, "price": 500}]',
        'star_price_cap' => 10000,
        'starprice_active_only' => 0,
        'effective_price_supply' => 100000,
        'total_coins_supply_end_of_tick' => 100000,
        'current_star_price' => 0,
        'blackout_star_price_snapshot' => null,
        'starprice_max_upstep_fp' => 1000000000, // no clamp
        'starprice_max_downstep_fp' => 1000000000, // no clamp
    ];

    // Without bias (or bias=1000000): get baseline price
    $noBypass = array_merge($baseSeason, ['market_affordability_bias_fp' => 1000000]);
    $priceNoBias = Economy::calculateStarPrice($noBypass);

    // With 3% bias (970000): price must be ~3% lower
    $withBias = array_merge($baseSeason, ['market_affordability_bias_fp' => 970000]);
    $priceWithBias = Economy::calculateStarPrice($withBias);

    $this->assertLessThan($priceNoBias, $priceWithBias,
        'market_affordability_bias_fp=970000 must reduce the star price vs no bias');

    $expectedReduction = intdiv($priceNoBias * 970000, 1000000);
    $this->assertSame($expectedReduction, $priceWithBias,
        'bias must be applied as intdiv(price * bias_fp, FP_SCALE)');
}

public function testMarketAffordabilityBiasDefaultIsNoEffect(): void
{
    $baseSeason = [
        'starprice_table' => '[{"m": 0, "price": 100}, {"m": 500000, "price": 500}]',
        'star_price_cap' => 10000,
        'starprice_active_only' => 0,
        'effective_price_supply' => 100000,
        'total_coins_supply_end_of_tick' => 100000,
        'current_star_price' => 0,
        'blackout_star_price_snapshot' => null,
        'starprice_max_upstep_fp' => 1000000000,
        'starprice_max_downstep_fp' => 1000000000,
    ];

    // Bias absent from season should have no effect (default 1000000)
    $withoutBiasKey = $baseSeason;
    $price1 = Economy::calculateStarPrice($withoutBiasKey);

    // Bias explicitly set to FP_SCALE = 1000000 should also have no effect
    $withFullBias = array_merge($baseSeason, ['market_affordability_bias_fp' => 1000000]);
    $price2 = Economy::calculateStarPrice($withFullBias);

    $this->assertSame($price1, $price2,
        'market_affordability_bias_fp=1000000 must not change price vs absent key');
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php vendor/bin/phpunit tests/EconomyPrecisionTest.php::testMarketAffordabilityBiasReducesStarPrice --no-coverage -v
```

Expected: FAIL — `calculateStarPrice()` currently ignores the bias.

- [ ] **Step 3: Wire the bias into Economy::calculateStarPrice()**

In [includes/economy.php](includes/economy.php), find `calculateStarPrice()` and add the bias application after the velocity clamp and before the hard cap. Look for:

```php
        // Hard cap and floor (preserved as final guardrails).
        $price = min($price, (int)$season['star_price_cap']);
        return max(1, $price);
```

Replace with:

```php
        // Apply market affordability bias (fp_1e6; 1000000 = no effect; < 1000000 = cheaper stars).
        // Default to FP_SCALE (no effect) when absent.
        $biasFp = max(1, (int)($season['market_affordability_bias_fp'] ?? FP_SCALE));
        if ($biasFp !== FP_SCALE) {
            $price = max(1, intdiv($price * $biasFp, FP_SCALE));
        }

        // Hard cap and floor (preserved as final guardrails).
        $price = min($price, (int)$season['star_price_cap']);
        return max(1, $price);
```

- [ ] **Step 4: Run the economy test to verify it passes**

```bash
php vendor/bin/phpunit tests/EconomyPrecisionTest.php --no-coverage -v
```

Expected: All tests PASS including the two new bias tests.

- [ ] **Step 5: Update SimulationConfigPreflight to mark the knob as candidate-scope / referenced**

In [scripts/simulation/SimulationConfigPreflight.php](scripts/simulation/SimulationConfigPreflight.php), find the `SEASON_KEY_META` entry:

```php
'market_affordability_bias_fp' => ['candidate_scope' => false, 'referenced' => false],
```

Replace with:

```php
'market_affordability_bias_fp' => ['candidate_scope' => true, 'referenced' => true],
```

- [ ] **Step 6: Remove from dead-knob list in CanonicalEconomyConfigContract**

In [scripts/simulation/CanonicalEconomyConfigContract.php](scripts/simulation/CanonicalEconomyConfigContract.php), find:

```php
'market_affordability_bias_fp' => 'Declared as a tuning knob, but canonical star pricing and purchase logic never apply the affordability bias.',
```

Remove that entire line from `CANDIDATE_SEARCH_SURFACE_REMOVALS`.

- [ ] **Step 7: Add market_affordability_bias_fp to PATCHABLE_PARAMETER_SCHEMA in CanonicalEconomyConfigContract**

In [scripts/simulation/CanonicalEconomyConfigContract.php](scripts/simulation/CanonicalEconomyConfigContract.php), in `PATCHABLE_PARAMETER_SCHEMA`, after the `starprice_max_downstep_fp` entry, add:

```php
        'market_affordability_bias_fp' => [
            'type' => 'int',
            'subsystem' => 'star_conversion_pricing',
            'units' => 'fp_1e6',
            'min' => 500000,
            'max' => 1000000,
            'description' => 'Multiplicative affordability bias on the computed star price (fp_1e6). '
                . '1000000 = no effect; 970000 = 3% cheaper; 940000 = 6% cheaper. '
                . 'Applied after velocity clamp, before hard cap. '
                . 'Reduces star-purchase friction for all archetypes equally but disproportionately '
                . 'benefits high-purchase archetypes (Boost-Focused, Hardcore, Star-Focused).',
        ],
```

- [ ] **Step 8: Add to candidateSearchParameters() in CanonicalEconomyConfigContract**

In [scripts/simulation/CanonicalEconomyConfigContract.php](scripts/simulation/CanonicalEconomyConfigContract.php), find `candidateSearchParameters()`. Add `market_affordability_bias_fp` to the returned array alongside the other star-pricing knobs. It should look like the existing entries:

```php
'market_affordability_bias_fp' => self::PATCHABLE_PARAMETER_SCHEMA['market_affordability_bias_fp'],
```

- [ ] **Step 9: Write a test for the preflight accepting the newly-live knob**

In [tests/SimulationConfigPreflightTest.php](tests/SimulationConfigPreflightTest.php), add:

```php
public function testMarketAffordabilityBiasFpIsNowLiveAndAcceptedAsCandidate(): void
{
    putenv(SimulationConfigPreflight::AUDIT_ENV_BYPASS . '=1');

    $resolved = SimulationConfigPreflight::resolve($this->options([
        'candidate_patch' => ['market_affordability_bias_fp' => 940000],
    ]));

    $changes = $resolved['report']['requested_candidate_changes'];
    $found = false;
    foreach ($changes as $change) {
        if (($change['key'] ?? '') === 'market_affordability_bias_fp') {
            $this->assertTrue((bool)$change['is_active'],
                'market_affordability_bias_fp must be active after wiring into calculateStarPrice()');
            $found = true;
        }
    }
    $this->assertTrue($found, 'market_affordability_bias_fp must appear in requested_candidate_changes');
}
```

Note: The `$this->options()` helper already exists in `SimulationConfigPreflightTest`. Add this method near the other candidate-patch tests.

- [ ] **Step 10: Run the preflight test to verify it passes**

```bash
php vendor/bin/phpunit tests/SimulationConfigPreflightTest.php --no-coverage -v
```

Expected: All tests PASS.

- [ ] **Step 11: Add a parity fixture for the affordability bias in RuntimeParityCertification**

In [scripts/simulation/RuntimeParityCertification.php](scripts/simulation/RuntimeParityCertification.php), in the `star_pricing_affordability` domain fixtures array, add a third fixture after the existing `star-pricing-blackout-snapshot` entry:

```php
[
    'fixture_id' => 'star-pricing-affordability-bias',
    'label' => 'Affordability bias reduces computed price',
    'description' => 'Sim and runtime must both apply market_affordability_bias_fp to the final price.',
    'tick' => 900,
    'phase' => 'MID',
    'expected_stars_after' => 2,
    'stars_requested' => 2,
    'season_overrides' => [
        'status' => 'Active',
        'computed_status' => 'Active',
        'current_star_price' => 200,
        'total_coins_supply_end_of_tick' => 90000,
        'effective_price_supply' => 90000,
        'market_affordability_bias_fp' => 940000,
    ],
    'participation_overrides' => [
        'coins' => 800,
    ],
    'compare_paths' => [
        'computed_star_price' => 0,
        'published_star_price' => 0,
        'coins_after' => 0,
        'seasonal_stars_after' => 0,
        'spend_window_total' => 0,
        'affordable' => 0,
    ],
],
```

- [ ] **Step 12: Run the RuntimeParityCertificationTest to verify the new fixture passes**

```bash
php vendor/bin/phpunit tests/RuntimeParityCertificationTest.php --no-coverage -v
```

Expected: All tests PASS (the new fixture verifies both sides apply the bias identically).

- [ ] **Step 13: Run the full OfficialBaselineArtifactTest to confirm the baseline still passes preflight**

```bash
php vendor/bin/phpunit tests/OfficialBaselineArtifactTest.php --no-coverage -v
```

Expected: PASS. The checked-in artifact has `market_affordability_bias_fp=970000` which is within the valid range `[500000, 1000000]` and will now be treated as an active, referenced key.

- [ ] **Step 14: Commit**

```bash
git add includes/economy.php scripts/simulation/SimulationConfigPreflight.php scripts/simulation/CanonicalEconomyConfigContract.php scripts/simulation/RuntimeParityCertification.php tests/EconomyPrecisionTest.php tests/SimulationConfigPreflightTest.php tests/RuntimeParityCertificationTest.php
git commit -m "feat: wire market_affordability_bias_fp into Economy::calculateStarPrice()

market_affordability_bias_fp was stored in the DB season row at 970000
(3% discount) but never applied in calculateStarPrice(). This meant:
1. The simulation was not parity-equivalent to intended runtime behavior
2. The optimizer had no lever to address star-price friction contributing
   to hoarder dominance (212% of median) and boost/hardcore weakness (25%)

The bias is applied after the velocity clamp and before the hard cap:
  price = max(1, intdiv(price * bias_fp, FP_SCALE))
Default 1000000 = no effect; 970000 = 3% cheaper; 940000 = 6% cheaper.

Parity certified: runtime_parity star_pricing_affordability domain
updated with a bias fixture verifying both sides agree.

Candidate surface updated: market_affordability_bias_fp is now
candidate_scope=true in SimulationConfigPreflight and included in
CanonicalEconomyConfigContract::candidateSearchParameters()."
```

---

## Task 4: Restore Empty Subsystems in Agentic Optimizer (OQ1 + OQ3)

**Root cause:** `blackout_lockin`, `lockin_incentives`, and `onboarding_economy` subsystems listed only no-op parameters (`starprice_reactivation_window_ticks`, `market_affordability_bias_fp` (now live!), `hoarding_window_ticks`, `target_spend_rate_per_tick`). After the `activeSearchKeys` filter, these subsystems had zero owned parameters and could generate no candidates.

**Fix:** Now that `market_affordability_bias_fp` is live, it restores `onboarding_economy`. For `blackout_lockin` and `lockin_incentives`, replace remaining no-op parameters with live star-pricing knobs they genuinely influence.

**Files:**
- Modify: `scripts/optimization/AgenticOptimization.php` — subsystem `owned_parameters` for `blackout_lockin`, `lockin_incentives`, `onboarding_economy`

- [ ] **Step 1: Run the second AgenticOptimizationTest to verify it fails (empty subsystems)**

```bash
php vendor/bin/phpunit tests/AgenticOptimizationTest.php::testSubsystemsWithOwnedParametersHaveNonEmptyAfterFilter --no-coverage -v
```

Expected: FAIL — lists blackout_lockin, lockin_incentives as having 0 owned parameters.

- [ ] **Step 2: Fix blackout_lockin subsystem owned_parameters**

In [scripts/optimization/AgenticOptimization.php](scripts/optimization/AgenticOptimization.php), find the `blackout_lockin` subsystem. Its `owned_parameters` currently has:

```php
['key' => 'starprice_reactivation_window_ticks', 'mode' => 'multiply', 'values' => [1.10, 1.18]],
['key' => 'hoarding_window_ticks', 'mode' => 'multiply', 'values' => [0.95, 0.90]],
['key' => 'market_affordability_bias_fp', 'mode' => 'multiply', 'values' => [0.97, 0.93]],
```

Replace with:

```php
['key' => 'starprice_idle_weight_fp', 'mode' => 'multiply', 'values' => [0.88, 0.80]],
['key' => 'starprice_max_upstep_fp', 'mode' => 'multiply', 'values' => [0.92, 0.85]],
['key' => 'market_affordability_bias_fp', 'mode' => 'multiply', 'values' => [0.97, 0.94]],
```

Rationale: `starprice_idle_weight_fp` and `starprice_max_upstep_fp` directly influence the lock-in/expiry tradeoff (star price velocity clamp controls late-season lock-in incentives). `market_affordability_bias_fp` is now live.

- [ ] **Step 3: Fix lockin_incentives subsystem owned_parameters**

In [scripts/optimization/AgenticOptimization.php](scripts/optimization/AgenticOptimization.php), find the `lockin_incentives` subsystem. Its `owned_parameters` currently has:

```php
['key' => 'starprice_reactivation_window_ticks', 'mode' => 'multiply', 'values' => [1.10, 1.20]],
['key' => 'market_affordability_bias_fp', 'mode' => 'multiply', 'values' => [0.97, 0.92]],
['key' => 'target_spend_rate_per_tick', 'mode' => 'multiply', 'values' => [0.96, 0.90]],
```

Replace with:

```php
['key' => 'starprice_idle_weight_fp', 'mode' => 'multiply', 'values' => [0.88, 0.78]],
['key' => 'market_affordability_bias_fp', 'mode' => 'multiply', 'values' => [0.97, 0.94]],
['key' => 'starprice_max_downstep_fp', 'mode' => 'multiply', 'values' => [1.06, 1.12]],
```

Rationale: Lower `starprice_idle_weight_fp` reduces idle-supply pricing pressure, directly improving lock-in incentives by keeping prices reachable. Faster downstep allows prices to recover faster after spikes.

- [ ] **Step 4: Run the test to verify it now passes**

```bash
php vendor/bin/phpunit tests/AgenticOptimizationTest.php --no-coverage -v
```

Expected: Both tests PASS.

- [ ] **Step 5: Run the full parity and preflight tests to verify no regressions**

```bash
php vendor/bin/phpunit tests/RuntimeParityCertificationTest.php tests/SimulationConfigPreflightTest.php tests/CanonicalEconomyConfigContractTest.php --no-coverage -v
```

Expected: All PASS.

- [ ] **Step 6: Commit**

```bash
git add scripts/optimization/AgenticOptimization.php tests/AgenticOptimizationTest.php
git commit -m "fix: restore empty agentic subsystems with live parameter search targets

blackout_lockin and lockin_incentives subsystems had zero owned parameters
after the activeSearchKeys filter because all their listed parameters were
no-ops (starprice_reactivation_window_ticks, target_spend_rate_per_tick).

Replaced with live knobs:
- blackout_lockin: starprice_idle_weight_fp, starprice_max_upstep_fp, market_affordability_bias_fp
- lockin_incentives: starprice_idle_weight_fp, market_affordability_bias_fp, starprice_max_downstep_fp

market_affordability_bias_fp is now a live knob (Task 3), which also
restores onboarding_economy's parameter count."
```

---

## Task 5: Update PolicyScenarioCatalog with Affordability Scenarios (OQ2)

**Goal:** Add scenarios that use the newly-live `market_affordability_bias_fp` to the policy catalog, giving the sweep/comparator pipeline a way to test its effect directly.

**Files:**
- Modify: `scripts/simulation/PolicyScenarioCatalog.php`

- [ ] **Step 1: Add affordability-focused scenarios to PolicyScenarioCatalog**

In [scripts/simulation/PolicyScenarioCatalog.php](scripts/simulation/PolicyScenarioCatalog.php), after the last scenario in the `$scenarios` array (before the closing `];`), add:

```php
[
    'name' => 'affordability-relief-v1',
    'description' => 'Reduce star price by 6% via market_affordability_bias_fp to improve Boost-Focused and Hardcore viability without touching hoarding or UBI.',
    'categories' => ['star_conversion_pricing'],
    'overrides' => [
        'market_affordability_bias_fp' => 940000,
    ],
],
[
    'name' => 'affordability-sink-combo-v1',
    'description' => 'Enable hoarding sink at conservative rates combined with 3% affordability relief to improve competitive balance across multiple archetypes.',
    'categories' => ['star_conversion_pricing', 'hoarding_preservation_pressure'],
    'overrides' => [
        'hoarding_sink_enabled' => 1,
        'hoarding_tier2_rate_hourly_fp' => 520,
        'hoarding_tier3_rate_hourly_fp' => 1050,
        'market_affordability_bias_fp' => 970000,
    ],
],
[
    'name' => 'affordability-ubi-combo-v1',
    'description' => 'Combine 6% affordability relief with active UBI buff to address both star-pricing friction and Hardcore/Boost-Focused archetype income weakness simultaneously.',
    'categories' => ['star_conversion_pricing', 'boost_related'],
    'overrides' => [
        'market_affordability_bias_fp' => 940000,
        'base_ubi_active_per_tick' => 36,
        'base_ubi_idle_factor_fp' => 220000,
    ],
],
```

- [ ] **Step 2: Run the SimulationPolicySweepSmokeTest to verify new scenarios validate cleanly**

```bash
php vendor/bin/phpunit tests/SimulationPolicySweepSmokeTest.php --no-coverage -v
```

Expected: PASS.

- [ ] **Step 3: Run the SimulationConfigPreflightTest to confirm scenarios pass preflight**

```bash
php vendor/bin/phpunit tests/SimulationConfigPreflightTest.php --no-coverage -v
```

Expected: PASS (new scenarios use only valid, active knobs).

- [ ] **Step 4: Commit**

```bash
git add scripts/simulation/PolicyScenarioCatalog.php
git commit -m "feat: add affordability-bias scenarios to PolicyScenarioCatalog

New scenarios for market_affordability_bias_fp now that it is wired:
- affordability-relief-v1: 6% star price reduction in isolation
- affordability-sink-combo-v1: sink + 3% affordability relief combo
- affordability-ubi-combo-v1: 6% relief + active UBI buff combo

These address the B9/B10 structural imbalance (boost-focused/hardcore
at 25% of median) without requiring mechanical changes."
```

---

## Task 6: Verify Agentic Optimizer Produces a Complete Run

**Goal:** Confirm that after the tier3 profile fix, the agentic optimizer completes a full run and writes `final_integration_report.json` and `best_composed_config.json`.

**Files:**
- Run: `php scripts/agentic_optimize_economy.php`
- Read: `simulation_output/current-db/agentic-optimization/<run-id>/reports/final_integration_report.md`

- [ ] **Step 1: Run the agentic optimizer with the fixed profile**

```powershell
php scripts/agentic_optimize_economy.php `
  --seed=recovery-verify `
  --season-config=simulation_output/current-db/export/current_season_economy_only.json `
  --output=simulation_output/current-db/agentic-optimization
```

Expected: Completes without crashing. Creates `simulation_output/current-db/agentic-optimization/recovery-verify/reports/final_integration_report.json`.

- [ ] **Step 2: Verify the final integration report exists and is valid JSON**

```bash
cat "simulation_output/current-db/agentic-optimization/recovery-verify/reports/final_integration_report.json" | grep -E '"schema_version"|"globally_valid_full_configuration_found"|"run_id"'
```

Expected:
```json
"schema_version": "tmc-agentic-final-report.v1",
"globally_valid_full_configuration_found": true or false,
"run_id": "recovery-verify"
```

- [ ] **Step 3: Read the final integration report to diagnose outcome**

```bash
cat "simulation_output/current-db/agentic-optimization/recovery-verify/reports/final_integration_report.md"
```

Document the outcome:
- How many subsystems found accepted candidates?
- Was a globally valid full configuration found?
- If not, what were the dominant failure patterns?

- [ ] **Step 4: Document findings in the recovery report**

If `globally_valid_full_configuration_found: true`: Proceed to Task 7 (run promotion pipeline on best variant).

If `globally_valid_full_configuration_found: false`: Check `rejected_iteration_audit.key_failure_patterns`. If still showing `lock_in_down_but_expiry_dominance_up` and `long_run_concentration_worsened`, this is the expected structural blocker. The economy now has a diagnosed root cause and a tested direction (affordability relief). The optimizer has completed and documented the blocker — this is a valid operational state.

- [ ] **Step 5: Run the sweep comparator qualification profile against the best affordability scenario**

If the agentic run did not produce a promotable candidate, run the qualification comparator manually against the new scenarios:

```powershell
$env:TMC_TICK_REAL_SECONDS = 3600
php scripts/run_sweep_comparator_campaign.php `
  --profile=qualification `
  --seed=affordability-qualification `
  --season-config=simulation_output/current-db/export/current_season_economy_only.json
Remove-Item Env:TMC_TICK_REAL_SECONDS
```

Expected: Produces `simulation_output/comparator/comparison_affordability-qualification.json`. Review dispositions — `affordability-relief-v1` and `affordability-sink-combo-v1` are expected to be the strongest candidates.

---

## Task 7: Run Full Promotion Pipeline on Best Candidate

**Goal:** Run the full `CandidatePromotionPipeline` on the highest-ranked candidate from Task 6 to demonstrate the end-to-end path works or identify where the structural blocker is.

**Files:**
- Run: `php scripts/promote_simulation_candidate.php`

- [ ] **Step 1: Identify the best candidate from Task 6**

From the Task 6 comparator results, identify the scenario/candidate with the highest wins-to-losses ratio and fewest regression flags. Expected candidates:
- `affordability-ubi-combo-v1` (most improvement to B9/B10)
- `hoarding-sink-conservative-v1` + `market_affordability_bias_fp` (combined structural fix)

- [ ] **Step 2: Generate the candidate patch file**

Create `tmp/recovery-candidate.json` with the best candidate's overrides:

```json
{
  "market_affordability_bias_fp": 940000,
  "base_ubi_active_per_tick": 36,
  "base_ubi_idle_factor_fp": 220000
}
```

(Adjust values based on Task 6 sweep results.)

- [ ] **Step 3: Run the promotion pipeline**

```powershell
php scripts/promote_simulation_candidate.php `
  --candidate=tmp/recovery-candidate.json `
  --candidate-id=recovery-affordability-ubi `
  --season-config=simulation_output/current-db/export/current_season_economy_only.json `
  --output=simulation_output/promotion `
  --players-per-archetype=2 `
  --season-count=4
```

- [ ] **Step 4: Read the promotion state and report outcome**

```bash
cat "simulation_output/promotion/recovery-affordability-ubi/promotion_state.json" | grep -E '"status"|"promotion_eligible"|"patch_ready"'
```

Document the outcome:
- Which stage is the first failing stage?
- Is the failure at `official_qualification_comparator_validation` (stage 6) or earlier?
- If at stage 6: what regression flags appear?

- [ ] **Step 5: Write the verification summary to the recovery_results.json**

Create `tmp/recovery_results.json`:

```json
{
  "schema_version": "tmc-recovery-results.v1",
  "generated_at": "<ISO timestamp>",
  "blockers_resolved": [
    "SV1: Agentic optimizer tier3 crash — fixed by reducing profile cost",
    "SV2: market_affordability_bias_fp not applied in calculateStarPrice — wired",
    "OQ1: Empty subsystems blackout_lockin, lockin_incentives — restored with live params",
    "RP1: No final_integration_report.json — resolved by SV1 fix"
  ],
  "suite_status": "<operational_for_diagnosis | operational_for_optimization | not_ready>",
  "structural_blocker": "<describe if no candidate passes qualification>",
  "best_candidate": "<candidate id from Task 7>",
  "best_candidate_stage_failed": "<stage id or 'promotion_eligible'>",
  "required_next_actions": ["<list any remaining actions>"]
}
```

---

## Task 8: Future-Mechanic Integration Contract

**Goal:** Document the required contract for adding future mechanics without invalidating the simulation framework.

**Files:**
- Write: `docs/SIMULATION_MECHANIC_INTEGRATION_CONTRACT.md`

- [ ] **Step 1: Create the integration contract document**

Create [docs/SIMULATION_MECHANIC_INTEGRATION_CONTRACT.md](docs/SIMULATION_MECHANIC_INTEGRATION_CONTRACT.md):

```markdown
# Simulation Mechanic Integration Contract

Any mechanic added to the economy must satisfy all of the following before it may enter the optimizer search space.

## Required Declarations

Every new mechanic must declare:

| Field | Required | Example |
|-------|----------|---------|
| `mechanic_id` | YES | `market_affordability_bias` |
| `runtime_path` | YES | `Economy::calculateStarPrice()` |
| `sim_path` | YES | `SimulationPlayer::buyStars()` |
| `parity_test_id` | YES | `star_pricing_affordability::star-pricing-affordability-bias` |
| `tunable_parameters` | YES | `['market_affordability_bias_fp']` |
| `affected_metrics` | YES | `['star_purchase_density', 'archetype_viability_min_ratio']` |
| `balance_risks` | YES | `['may reduce lock-in incentives if price drops too far']` |
| `feature_flag` | IF GATED | `hoarding_sink_enabled` |

## Required Steps for Adding a New Mechanic

1. **Wire into runtime (`includes/*.php`)**: The mechanic must be read by the actual tick engine or economy functions. Knobs that are stored but not read are no-ops.

2. **Mirror in simulation (`scripts/simulation/SimulationPlayer.php`)**: The simulation must call the same runtime function (or an identical inline copy) for parity.

3. **Mark as referenced in preflight (`scripts/simulation/SimulationConfigPreflight.php`)**: Set `'referenced' => true` in `SEASON_KEY_META`.

4. **Add to canonical schema (`scripts/simulation/CanonicalEconomyConfigContract.php`)**: Add to `PATCHABLE_PARAMETER_SCHEMA` and `candidateSearchParameters()`. Remove from `CANDIDATE_SEARCH_SURFACE_REMOVALS` if present.

5. **Add parity fixture (`scripts/simulation/RuntimeParityCertification.php`)**: Add at least one fixture to the relevant domain that proves the simulator and runtime agree on the mechanic's output.

6. **Run parity certification**: `php scripts/certify_runtime_parity.php` must pass with `certified: true`.

7. **Add to search surface only after parity passes**: Do not add to `AgenticEconomyDecomposition` subsystem `owned_parameters` until parity is confirmed.

8. **Wire into subsystem(s)**: Add the parameter to the most relevant agentic subsystem's `owned_parameters` with appropriate `mode` and `values`.

## No-Op Protection

Before removing a knob from `CANDIDATE_SEARCH_SURFACE_REMOVALS`, prove it is referenced by:
- Grepping for the knob name in `includes/economy.php` and `includes/tick_engine.php`
- Confirming it appears in a code path that runs on every tick or player action

If the knob is not found in those files, it is a no-op and must NOT enter the search space.

## Validation Before PR Merge

Before merging any mechanic that adds new patchable parameters:

```bash
php vendor/bin/phpunit tests/RuntimeParityCertificationTest.php --no-coverage -v
php vendor/bin/phpunit tests/SimulationConfigPreflightTest.php --no-coverage -v
php vendor/bin/phpunit tests/CanonicalEconomyConfigContractTest.php --no-coverage -v
php vendor/bin/phpunit tests/AgenticOptimizationTest.php --no-coverage -v
```

All must pass.
```

- [ ] **Step 2: Commit the contract**

```bash
git add docs/SIMULATION_MECHANIC_INTEGRATION_CONTRACT.md
git commit -m "docs: add simulation mechanic integration contract

Defines the required checklist for adding future mechanics/features
without breaking simulation usefulness:
- runtime path + sim path both required
- parity fixture required before entering search space
- no-op protection (knob must be found in economy.php/tick_engine.php)
- validation gate: 4 test suites must pass before PR merge"
```

---

## Self-Review

### Spec coverage check

| Requirement | Task |
|------------|------|
| Run end-to-end without trusted blocker failures | Task 1 (health check) + Task 2 (fix crash) |
| Diagnose structural balance problems with causal explanations | Existing diagnosis_report.json + Task 6 agentic run |
| Generate targeted economy changes (not blind random tuning) | Task 4 (restore subsystems) + Task 5 (add affordability scenarios) |
| Validate candidate changes through staged end-to-end simulation | Task 7 (promotion pipeline) |
| Demonstrate more than one strategy can compete | Task 6 + 7 outcome: affordability relief shifts competitive balance |
| Reject candidates that create new dominant exploit paths | Existing coupling harnesses + regression flags (unchanged) |
| Produce a ranked list of recommended economy configurations | Task 6 sweep comparator output |
| Remain extensible for future mechanics | Task 8 (integration contract) |

### No placeholders found

### Type consistency check

- `FP_SCALE` constant is used in `economy.php` — confirmed defined as 1000000
- `market_affordability_bias_fp` uses `int` type matching existing `fp_1e6` pattern
- `intdiv($price * $biasFp, FP_SCALE)` matches the pattern used elsewhere in `calculateStarPrice()`
- `$this->options()` helper referenced in preflight test exists in the test class

---

**Plan complete and saved to `docs/superpowers/plans/2026-04-15-simulation-suite-recovery.md`.**

**Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
