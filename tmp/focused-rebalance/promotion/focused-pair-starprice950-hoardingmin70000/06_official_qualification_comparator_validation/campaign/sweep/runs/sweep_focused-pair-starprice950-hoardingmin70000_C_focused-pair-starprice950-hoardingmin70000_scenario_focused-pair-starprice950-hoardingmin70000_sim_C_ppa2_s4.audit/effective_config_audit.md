# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `focused-pair-starprice950-hoardingmin70000|scenario|focused-pair-starprice950-hoardingmin70000|sim|C`
- Run Label: `sweep_focused-pair-starprice950-hoardingmin70000_C_focused-pair-starprice950-hoardingmin70000_scenario_focused-pair-starprice950-hoardingmin70000_sim_C_ppa2_s4`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_min_factor_fp` => active
  requested=70000 | effective=70000 | source=candidate_patch
- `season.starprice_max_upstep_fp` => active
  requested=950 | effective=950 | source=candidate_patch
