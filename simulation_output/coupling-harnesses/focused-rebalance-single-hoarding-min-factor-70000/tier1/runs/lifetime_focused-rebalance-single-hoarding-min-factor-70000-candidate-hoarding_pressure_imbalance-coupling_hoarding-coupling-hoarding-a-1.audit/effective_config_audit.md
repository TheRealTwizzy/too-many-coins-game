# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `coupling-hoarding-a`
- Run Label: `lifetime_focused-rebalance-single-hoarding-min-factor-70000-candidate-hoarding_pressure_imbalance-coupling_hoarding-coupling-hoarding-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_min_factor_fp` => active
  requested=70000 | effective=70000 | source=candidate_patch
