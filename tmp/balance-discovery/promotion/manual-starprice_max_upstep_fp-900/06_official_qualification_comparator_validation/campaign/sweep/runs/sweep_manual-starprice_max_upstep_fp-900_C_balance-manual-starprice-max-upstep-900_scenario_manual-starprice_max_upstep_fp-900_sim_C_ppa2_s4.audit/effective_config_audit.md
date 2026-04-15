# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `balance-manual-starprice-max-upstep-900|scenario|manual-starprice_max_upstep_fp-900|sim|C`
- Run Label: `sweep_manual-starprice_max_upstep_fp-900_C_balance-manual-starprice-max-upstep-900_scenario_manual-starprice_max_upstep_fp-900_sim_C_ppa2_s4`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=900 | effective=900 | source=candidate_patch
