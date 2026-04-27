# Tactical Boost Economy v2 Design

## Decision

Do not transfer the current `source/dev` economy changes to main/test yet. V1 reduced sigil throughput, but the diagnosis still shows one high-severity boost dominance finding and a medium sigil overabundance finding.

## Goal

Turn boosts from near-permanent income state into tactical windows that require meaningful sigil choices, while preserving low-inventory recovery and the canonical simulation config pipeline.

## Diagnosis Input

- V1 batch: `simulation_output/current-db/boost-scarcity-v1-batch-20260426-snapshot`
- V1 diagnosis: `simulation_output/current-db/boost-scarcity-v1-diagnosis-20260426-snapshot`
- Remaining high finding: boost contributes `100.4%` of the top-quartile coin delta.
- Remaining medium finding: median total sigil inventory is `389.1` per player, above the `20` finding threshold.
- Root issue discovered after V1: `BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT` caps active time extensions, but Tier 1 initial activation can still grant a 24-hour window.

## Recommended Approach

Use a focused V2 patch instead of a broad mechanic redesign.

1. Apply the boost time cap to initial activation duration.
2. Lower `SIGIL_DROP_CHANCE_FP` from `125000` to `10000`, a 1.0% base per-tick gate chance.
3. Keep inventory pressure and boost pressure from V1.
4. Keep runtime, API preview, parity certification, and simulation on the same `BoostCatalog` duration API.
5. Run focused PHPUnit checks first, then a full canonical batch/diagnosis before considering transfer to main/test.

## Tradeoffs

- A 1.0% base gate is a strong reduction, but the current 12.5% gate left total sigil acquisition around 389 per player even after V1 pressure.
- Applying the cap inside `BoostCatalog::getInitialDuration*ForTier()` preserves existing call sites and keeps runtime/simulation parity simple.
- Updating normalized catalog duration fields prevents the UI from advertising a longer initial window than the server grants.

## Greenlight Criteria

V2 is eligible for main/test playtesting only if the next diagnosis shows:

- No new preflight/config integrity failures.
- No simulation runs missing `effective_config.json` or `effective_config_audit.md`.
- Boost coin-share findings materially lower than V1.
- Sigil overabundance materially lower than V1.
- Lock-in and expiry do not regress into a clearly worse player-flow profile.

## Out Of Scope

- New freeze, theft, blackout, or star-market mechanics.
- Live deployment.
- Sandbox/live environment changes.
- Init/db behavior changes.
