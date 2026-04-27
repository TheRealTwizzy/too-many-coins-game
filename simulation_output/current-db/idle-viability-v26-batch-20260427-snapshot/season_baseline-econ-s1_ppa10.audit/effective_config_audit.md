# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `baseline-econ-s1`
- Run Label: `season_baseline-econ-s1_ppa10`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.base_ubi_idle_factor_fp` => active
  requested=300000 | effective=300000 | source=candidate_patch
