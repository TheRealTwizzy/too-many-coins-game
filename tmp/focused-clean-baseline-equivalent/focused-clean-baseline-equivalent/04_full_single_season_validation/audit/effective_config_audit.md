# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `focused-clean-baseline-equivalent|promotion|B`
- Run Label: `focused-clean-baseline-equivalent-promotion-B`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.base_ubi_active_per_tick` => active
  requested=30 | effective=30 | source=candidate_patch
