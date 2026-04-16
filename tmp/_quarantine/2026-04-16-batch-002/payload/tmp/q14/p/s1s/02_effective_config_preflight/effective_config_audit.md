# Effective Config Audit

- Status: `pass`
- Simulator: `promotion-preflight`
- Seed: `q14-s1s`
- Run Label: `s1s-promotion-preflight`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.base_ubi_active_per_tick` => active
  requested=32 | effective=32 | source=candidate_patch
