# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `focused-single-hoarding-sink-enabled|scenario|focused-single-hoarding-sink-enabled|sim|C`
- Run Label: `sweep_focused-single-hoarding-sink-enabled_C_focused-single-hoarding-sink-enabled_scenario_focused-single-hoarding-sink-enabled_sim_C_ppa2_s4`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
