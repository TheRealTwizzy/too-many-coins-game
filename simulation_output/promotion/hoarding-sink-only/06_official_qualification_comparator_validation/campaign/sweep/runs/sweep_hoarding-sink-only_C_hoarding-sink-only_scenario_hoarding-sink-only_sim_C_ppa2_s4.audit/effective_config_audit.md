# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `hoarding-sink-only|scenario|hoarding-sink-only|sim|C`
- Run Label: `sweep_hoarding-sink-only_C_hoarding-sink-only_scenario_hoarding-sink-only_sim_C_ppa2_s4`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_safe_hours` => active
  requested=24 | effective=24 | source=candidate_patch
- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
