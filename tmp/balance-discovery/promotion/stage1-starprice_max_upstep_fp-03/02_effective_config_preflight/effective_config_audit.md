# Effective Config Audit

- Status: `pass`
- Simulator: `promotion-preflight`
- Seed: `balance-stage1-starprice-max-upstep-750`
- Run Label: `stage1-starprice_max_upstep_fp-03-promotion-preflight`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=750 | effective=750 | source=candidate_patch
