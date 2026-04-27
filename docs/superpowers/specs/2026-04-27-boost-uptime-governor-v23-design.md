# Boost Uptime Governor v2.3 Design

Date: 2026-04-27
Branch: source/dev

## Problem

V2.2 was verified, but its diagnosis stayed red:

- Top-quartile coin delta was 83.9% boost-driven.
- Every archetype earned more than 60% of coins while boosted.
- Median sigil inventory remained very high at 87.7.

The clearest failure is boost uptime. The four-hour cap is applied as a rolling cap from each time purchase, so players with enough sigils can keep extending boosts and remain boosted for a large share of the season.

## Goal

Make boosts tactical sessions with real downtime while preserving v2.2 power values:

- Keep v2.2 initial durations, extension durations, and power values.
- Cap a boost session from its original activation tick.
- Preserve `activated_tick` when power or time is added to an active boost.
- Add a four-hour recovery window after a boost session ends before a player can activate a new boost.
- Include recovery in canonical effective-config audit output.

## Design

An active SELF boost has one session start: `activated_tick`. Its maximum expiry is:

`activated_tick + BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT`

Time purchases can extend only up to that fixed cap. Power purchases can still increase modifier power using the existing v2.2 total cap logic, but they do not reset the session start.

When a boost expires, simulation stores `recovery_until_tick`. Runtime paths infer recovery from the latest SELF boost row's `expires_tick`, so this patch does not require a database schema change.

## Success Criteria

- Focused tests fail before implementation and pass after implementation.
- Runtime preview, action execution, simulator, and parity certification use the fixed session cap.
- Simulation preflight audit records the recovery window.
- Full v2.3 baseline batch completes 21/21 runs with audit artifacts before any promotion decision.
- Diagnosis materially lowers boost uptime or clearly proves a different next lever is required.
