# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `coupling-hoarding-a`
- Run Label: `season_recovery-verify-concentration_control-cand-1-coupling-candidate-hoarding_pressure_imbalance-coupling_hoarding-coupling-hoarding-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.market_affordability_bias_fp` => active
  requested=950600 | effective=950600 | source=candidate_patch
