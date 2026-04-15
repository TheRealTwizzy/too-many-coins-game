# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `focused-single-hoarding-sink-enabled|promotion|B`
- Run Label: `focused-single-hoarding-sink-enabled-promotion-B`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
