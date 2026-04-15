# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `coupling-hoarding-a`
- Run Label: `season_recovery-affordability-ubi-candidate-hoarding_pressure_imbalance-coupling_hoarding-coupling-hoarding-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.base_ubi_active_per_tick` => active
  requested=36 | effective=36 | source=candidate_patch
- `season.base_ubi_idle_factor_fp` => active
  requested=220000 | effective=220000 | source=candidate_patch
- `season.market_affordability_bias_fp` => active
  requested=940000 | effective=940000 | source=candidate_patch
