# Idle Viability v2.6 Design

## Context

The v2.5 boost recovery and sigil throttle batch completed with 21/21 simulations and valid audit artifacts, but it did not greenlight. The remaining HIGH finding was `non_viable_archetype`: `mostly_idle` scored at `49.52%` of the overall archetype baseline, just under the `50%` threshold.

v2.5 did clear the original boost dominance problem: top-quartile boost share of coin delta fell to `37.1%`, below the `40%` greenlight line. The next adjustment must preserve that result.

## Options Considered

1. Raise idle UBI factor from `250000` to `300000`.
   - Best fit. It directly helps the one failing archetype, also helps casual low-touch play, and does not touch boost power, sigil drop rate, hoarding sink, or star-pricing pressure.

2. Reduce active UBI or boost value further.
   - Riskier. It could clear the ratio by lowering the field instead of making idle play healthier, and it could flatten active gameplay.

3. Change the `mostly_idle` simulation policy.
   - Rejected. That would make the harness less representative without improving public play-test economy behavior.

## Design

Raise `base_ubi_idle_factor_fp` to `300000` for new seasons and for the play-test season promotion path. This is a 20% relative increase to idle UBI, but only a 5 percentage point absolute move in active-UBI share. A `270000` smoke test still landed mostly-idle at `49.63%`, so the stronger 30% value is the smallest screened value with practical clearance.

Keep v2.5 constants unchanged:

- `BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION = 43200`
- `SIGIL_DROP_CHANCE_FP = 3500`
- `SIGIL_BOOST_DROP_PRESSURE_MIN_FP = 100000`

Add candidate patch file support to the simulation CLIs and baseline batch runner so v2.6 validation can run as a canonical candidate patch:

- `simulate_contracts.php --candidate-patch=...`
- `simulate_economy.php --candidate-patch=...`
- `simulate_lifetime.php --candidate-patch=...`
- `run_baseline_batch.php --candidate-patch=...`

Each run must still generate `effective_config.json` and `effective_config_audit.md`, and the audit must show `base_ubi_idle_factor_fp = 300000` from `candidate_patch`.

## Promotion

If the diagnosis greenlights, ship a minimal play-test migration that updates eligible scheduled/active/blackout seasons from `250000` to `300000`. Do not reset gameplay state in this migration.

## Tests

- Add failing CLI tests proving candidate patch files flow into simulation audits.
- Add default-season/runtime tests proving new seasons use the v2.6 idle factor.
- Re-run focused PHP suites.
- Re-run canonical smoke, full batch, analysis, and diagnosis.
