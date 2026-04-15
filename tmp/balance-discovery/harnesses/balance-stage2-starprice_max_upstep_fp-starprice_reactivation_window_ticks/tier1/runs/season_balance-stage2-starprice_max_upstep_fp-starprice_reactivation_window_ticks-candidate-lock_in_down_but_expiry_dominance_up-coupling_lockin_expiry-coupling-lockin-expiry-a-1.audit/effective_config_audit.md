# Effective Config Audit

- Status: `fail`
- Simulator: `B`
- Seed: `coupling-lockin-expiry-a`
- Run Label: `season_balance-stage2-starprice_max_upstep_fp-starprice_reactivation_window_ticks-candidate-lock_in_down_but_expiry_dominance_up-coupling_lockin_expiry-coupling-lockin-expiry-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=750 | effective=750 | source=candidate_patch
- `season.starprice_reactivation_window_ticks` => inactive_unreferenced
  requested=101 | effective=101 | source=candidate_patch
  detail=Key resolves correctly but the simulator does not read it during B/C execution.
