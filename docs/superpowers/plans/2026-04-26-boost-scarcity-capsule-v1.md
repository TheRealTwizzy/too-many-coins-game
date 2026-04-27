# Boost Scarcity Capsule v1

> **For Trent:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan.

**Goal:** Reduce permanent boost-state dominance by making sigil drops cap-aware, inventory-aware, and boost-aware, while keeping low-inventory catch-up intact.

**Diagnosis Inputs**
- Baseline: `simulation_output/current-db/baseline-batch-20260426-snapshot`
- Diagnosis: `simulation_output/current-db/diagnosis-20260426-snapshot`
- Root issue: boost mechanic explains `100.4%` of top-quartile coin delta.
- Secondary issue: median sigil inventory is `1153.9` per player, far above `SIGIL_INVENTORY_TOTAL_CAP = 25`.

**Design**
- Random sigil drops stop when a player is at the configured total inventory cap.
- Sigil drop chance begins tapering after a low-inventory buffer, so early/catch-up players still see drops.
- Boosted players receive a lower sigil drop chance, making boost loops less self-sustaining.
- Boost time cap becomes a tactical 12-hour ceiling instead of a 48-hour ceiling.

**Implementation Tasks**
- [x] Add red tests to `tests/EconomyPrecisionTest.php`:
  - Inventory at total cap returns zero drop pressure and zero effective chance.
  - Low inventory keeps full pressure; mid inventory is dampened but not zero.
  - Boosted players have a lower effective sigil drop chance than unboosted players.
  - `evaluateSigilDropForTick()` returns no drops at cap even when deterministic rolls would otherwise produce drops.
- [x] Add red tests to `tests/BoostCatalogRuntimeTest.php`:
  - `BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT` is `12 * 60 * 60`.
  - Tier 5 initial boost duration remains a short burst window.
- [x] Implement focused config constants in `includes/config.php`:
  - Inventory pressure start/cap thresholds.
  - Boost pressure step, per-step penalty, and minimum multiplier.
- [x] Update `includes/economy.php`:
  - Add inventory pressure helper.
  - Add boost pressure helper.
  - Extend `sigilEffectiveDropChanceFp()` and `evaluateSigilDropForTick()` with optional participation and boost inputs.
  - Include pressure fields in drop metadata.
- [x] Update runtime parity call sites:
  - `includes/tick_engine.php` passes participation data and boost modifier into drop evaluation.
  - `scripts/simulation/SimulationPlayer.php` passes simulated participation data and current boost modifier.
- [x] Reduce boost time cap in `includes/boost_catalog.php` from 48 hours to 12 hours.
- [x] Verify:
  - Focused PHPUnit tests for economy and boost catalog.
  - Existing relevant parity/timing tests.
  - Simulation run continues to generate `effective_config.json` and `effective_config_audit.md`.
  - Economy diagnosis comparison shows boost dominance and inventory findings moving in the right direction.

**Rollback Plan**
- Revert the new pressure constants and helper wiring.
- Restore `BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT` to `48 * 60 * 60`.
- Re-run focused tests and baseline simulation to confirm previous behavior.
