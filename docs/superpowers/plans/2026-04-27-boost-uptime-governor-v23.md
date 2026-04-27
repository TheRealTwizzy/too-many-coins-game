# Boost Uptime Governor v2.3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn v2.2 boosts into capped sessions with a recovery window so boost uptime can no longer dominate season scoring.

**Architecture:** `BoostCatalog` owns session/recovery helper math. Runtime actions, API preview, simulator player logic, and runtime parity certification all call the same helpers. Preflight audit exposes the recovery constant so simulations cannot run without documenting the active governor value.

**Tech Stack:** PHP 8.5, PHPUnit 11, existing simulation scripts, existing browser frontend fallback data.

---

## File Structure

- Modify `includes/boost_catalog.php`: add recovery constant and helper methods for session cap and recovery ticks.
- Modify `scripts/simulation/SimulationPlayer.php`: store recovery state after expiry, block inactive activation during recovery, and cap time extensions by original activation tick.
- Modify `scripts/simulation/RuntimeParityCertification.php`: mirror simulator boost session behavior in runtime parity fixtures.
- Modify `includes/actions.php`: enforce fixed session cap and recovery in production boost purchases.
- Modify `api/index.php`: enforce the same fixed session cap and recovery in boost preview.
- Modify `scripts/simulation/SimulationConfigPreflight.php`: audit recovery constant.
- Modify `tests/SimulationConfigPreflightTest.php`: assert recovery appears in runtime audit.
- Create `tests/BoostUptimeGovernorTest.php`: focused red/green tests for session cap and recovery behavior.
- Modify `public/wiki/assets/wiki-data.js`: document boost recovery behavior.

## Implementation Checklist

- [ ] Add failing focused tests for fixed session cap, recovery blocking, and runtime audit recovery output.
- [ ] Add `BoostCatalog` recovery/session helper methods.
- [ ] Update simulator and runtime parity boost lifecycle.
- [ ] Update production action execution and API preview lifecycle.
- [ ] Update player-facing wiki text.
- [ ] Run focused syntax and PHPUnit verification.
- [ ] Run canonical v2.3 baseline batch, analysis, and diagnosis.
- [ ] Commit source/docs/tests if verification is ready.
