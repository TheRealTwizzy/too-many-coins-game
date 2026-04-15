# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `focused-single-starprice-max-upstep-950|scenario|focused-single-starprice-max-upstep-950|sim|B`
- Run Label: `sweep_focused-single-starprice-max-upstep-950_B_focused-single-starprice-max-upstep-950_scenario_focused-single-starprice-max-upstep-950_sim_B_ppa2`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=950 | effective=950 | source=candidate_patch
