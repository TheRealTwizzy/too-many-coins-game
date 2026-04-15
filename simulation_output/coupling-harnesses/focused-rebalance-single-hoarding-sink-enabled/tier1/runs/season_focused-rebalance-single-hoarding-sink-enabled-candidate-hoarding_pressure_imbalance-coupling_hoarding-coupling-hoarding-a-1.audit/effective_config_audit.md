# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `coupling-hoarding-a`
- Run Label: `season_focused-rebalance-single-hoarding-sink-enabled-candidate-hoarding_pressure_imbalance-coupling_hoarding-coupling-hoarding-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
