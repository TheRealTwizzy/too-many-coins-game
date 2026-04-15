# Effective Config Audit

- Status: `pass`
- Simulator: `promotion-preflight`
- Seed: `focused-single-hoarding-sink-enabled`
- Run Label: `focused-single-hoarding-sink-enabled-promotion-preflight`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
