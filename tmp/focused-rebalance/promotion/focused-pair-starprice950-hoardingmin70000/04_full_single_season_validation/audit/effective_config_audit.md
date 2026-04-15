# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `focused-pair-starprice950-hoardingmin70000|promotion|B`
- Run Label: `focused-pair-starprice950-hoardingmin70000-promotion-B`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_min_factor_fp` => active
  requested=70000 | effective=70000 | source=candidate_patch
- `season.starprice_max_upstep_fp` => active
  requested=950 | effective=950 | source=candidate_patch
