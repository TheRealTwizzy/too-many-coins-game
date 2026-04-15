# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `focused-safe24-reject|promotion|qualification|scenario|promotion-ready-focused-safe24-reject|sim|C`
- Run Label: `sweep_promotion-ready-focused-safe24-reject_C_focused-safe24-reject_promotion_qualification_scenario_promotion-ready-focused-safe24-reject_sim_C_ppa2_s4`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_safe_hours` => active
  requested=24 | effective=24 | source=candidate_patch
- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
