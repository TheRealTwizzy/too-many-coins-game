# Effective Config Audit

- Status: `pass`
- Simulator: `promotion-preflight`
- Seed: `balance-manual-starprice-max-upstep-900`
- Run Label: `manual-starprice_max_upstep_fp-900-promotion-preflight`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=900 | effective=900 | source=candidate_patch
