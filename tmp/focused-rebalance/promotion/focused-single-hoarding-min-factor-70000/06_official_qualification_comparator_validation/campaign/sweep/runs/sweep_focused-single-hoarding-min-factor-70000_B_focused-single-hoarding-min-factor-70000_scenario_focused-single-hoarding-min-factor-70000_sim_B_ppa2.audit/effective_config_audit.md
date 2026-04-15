# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `focused-single-hoarding-min-factor-70000|scenario|focused-single-hoarding-min-factor-70000|sim|B`
- Run Label: `sweep_focused-single-hoarding-min-factor-70000_B_focused-single-hoarding-min-factor-70000_scenario_focused-single-hoarding-min-factor-70000_sim_B_ppa2`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_min_factor_fp` => active
  requested=70000 | effective=70000 | source=candidate_patch
