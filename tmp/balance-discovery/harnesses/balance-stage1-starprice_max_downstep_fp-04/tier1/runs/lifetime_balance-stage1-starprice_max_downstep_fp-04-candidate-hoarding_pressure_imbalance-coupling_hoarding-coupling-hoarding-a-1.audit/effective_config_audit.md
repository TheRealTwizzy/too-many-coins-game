# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `coupling-hoarding-a`
- Run Label: `lifetime_balance-stage1-starprice_max_downstep_fp-04-candidate-hoarding_pressure_imbalance-coupling_hoarding-coupling-hoarding-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_downstep_fp` => active
  requested=9720 | effective=9720 | source=candidate_patch
