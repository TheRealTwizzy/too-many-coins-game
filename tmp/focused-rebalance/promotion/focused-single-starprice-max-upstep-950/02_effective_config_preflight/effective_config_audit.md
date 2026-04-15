# Effective Config Audit

- Status: `pass`
- Simulator: `promotion-preflight`
- Seed: `focused-single-starprice-max-upstep-950`
- Run Label: `focused-single-starprice-max-upstep-950-promotion-preflight`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=950 | effective=950 | source=candidate_patch
