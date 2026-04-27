# Boost Recovery And Sigil Throttle v2.5 Design

Date: 2026-04-27
Branch: source/dev

## Problem

V2.4 corrected the active boost power cap but did not greenlight the economy:

- Boost still explains 67.9% of the top-quartile coin delta.
- Most archetypes still earn more than 60% of coins while boosted.
- Median sigil acquisition throughput remains 87.5 per player, far above the 20-sigil overabundance threshold.

The cap fix proved that one SELF boost row cannot stack past 100%, so the remaining issue is sustainable uptime. A four-hour active session followed by a four-hour recovery window still allows near-alternating boosted play for players with enough sigil supply.

## Goal

Break the self-sustaining boost loop without removing tactical boost identity:

- Keep v2.2 burst windows and v2.4 per-product power cap.
- Increase post-session recovery so boost uptime has real downtime.
- Lower baseline sigil throughput enough to reduce repeated boost reactivation.
- Make boosted drops materially rarer while preserving low-inventory recovery when unboosted.
- Preserve canonical effective-config audit coverage for every changed runtime value.

## Options Considered

1. Lower boost power again.
   - This would reduce per-tick payoff but would not address the root loop: constant reactivation.
   - V2.4 already proved power cap enforcement was not the dominant lever.

2. Lower sigil drops only.
   - This directly reduces fuel, but risks starving utility actions and making progression feel empty.
   - It may require several rounds to find the right base rate.

3. Extend recovery and tighten boosted sigil drops, with a moderate base gate reduction.
   - This addresses both uptime and self-fueling while keeping unboosted catch-up intact.
   - This is the recommended path.

## Design

V2.5 changes three runtime constants:

- `BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION`: 4 hours -> 12 hours.
- `SIGIL_DROP_CHANCE_FP`: 10000 -> 3500, a 0.35% base per-tick gate.
- `SIGIL_BOOST_DROP_PRESSURE_MIN_FP`: 250000 -> 100000, so fully boosted players bottom out at 10% of their pre-boost drop chance instead of 25%.

The 12-hour recovery window turns boost into a limited tactical state rather than a half-season default. The 0.35% base gate targets a rough 65% throughput reduction from v2.4 while still leaving drops visible across a 14-day season. The boosted-drop floor makes boost chaining harder specifically for players already receiving boosted income.

No database schema or environment changes are required. Runtime, simulator, and parity paths already read these constants through `BoostCatalog` and `Economy`, so this patch is intentionally small.

## Success Criteria

- Focused tests fail before implementation and pass after implementation.
- Simulation preflight runtime audit reports the new recovery, base drop gate, and boost pressure floor.
- Canonical current-DB batch completes 21/21 with generated `effective_config.json` and `effective_config_audit.md`.
- Diagnosis has no HIGH severity findings.
- Boost share of top-quartile coin delta falls below the 40% high-severity threshold.
- Sigil overabundance materially improves from v2.4's 87.5 median acquired per player.

## Out Of Scope

- New UI or player-facing mechanics.
- Database migrations.
- Sandbox/live environment changes.
- Live deployment.
