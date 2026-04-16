# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `hoarding-sink-only|promotion|B`
- Run Label: `hoarding-sink-only-promotion-B`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_safe_hours` => active
  requested=24 | effective=24 | source=candidate_patch
- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
