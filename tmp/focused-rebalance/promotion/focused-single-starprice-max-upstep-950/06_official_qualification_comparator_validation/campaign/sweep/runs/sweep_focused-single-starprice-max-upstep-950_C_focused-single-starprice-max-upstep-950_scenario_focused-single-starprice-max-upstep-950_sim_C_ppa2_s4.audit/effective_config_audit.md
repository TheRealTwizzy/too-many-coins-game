# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `focused-single-starprice-max-upstep-950|scenario|focused-single-starprice-max-upstep-950|sim|C`
- Run Label: `sweep_focused-single-starprice-max-upstep-950_C_focused-single-starprice-max-upstep-950_scenario_focused-single-starprice-max-upstep-950_sim_C_ppa2_s4`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=950 | effective=950 | source=candidate_patch
