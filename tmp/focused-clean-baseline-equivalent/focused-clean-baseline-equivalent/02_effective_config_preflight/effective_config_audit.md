# Effective Config Audit

- Status: `pass`
- Simulator: `promotion-preflight`
- Seed: `focused-clean-baseline-equivalent`
- Run Label: `focused-clean-baseline-equivalent-promotion-preflight`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.base_ubi_active_per_tick` => active
  requested=30 | effective=30 | source=candidate_patch
