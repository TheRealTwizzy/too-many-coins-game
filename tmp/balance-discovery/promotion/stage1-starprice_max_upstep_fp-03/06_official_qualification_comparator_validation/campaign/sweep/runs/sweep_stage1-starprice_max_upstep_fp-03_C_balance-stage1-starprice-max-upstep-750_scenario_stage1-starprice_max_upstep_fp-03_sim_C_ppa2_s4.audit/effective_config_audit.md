# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `balance-stage1-starprice-max-upstep-750|scenario|stage1-starprice_max_upstep_fp-03|sim|C`
- Run Label: `sweep_stage1-starprice_max_upstep_fp-03_C_balance-stage1-starprice-max-upstep-750_scenario_stage1-starprice_max_upstep_fp-03_sim_C_ppa2_s4`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=750 | effective=750 | source=candidate_patch
