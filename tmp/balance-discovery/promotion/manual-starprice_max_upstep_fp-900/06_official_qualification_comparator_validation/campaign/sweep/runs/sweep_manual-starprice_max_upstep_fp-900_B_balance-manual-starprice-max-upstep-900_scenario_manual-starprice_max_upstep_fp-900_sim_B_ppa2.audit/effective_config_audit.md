# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `balance-manual-starprice-max-upstep-900|scenario|manual-starprice_max_upstep_fp-900|sim|B`
- Run Label: `sweep_manual-starprice_max_upstep_fp-900_B_balance-manual-starprice-max-upstep-900_scenario_manual-starprice_max_upstep_fp-900_sim_B_ppa2`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=900 | effective=900 | source=candidate_patch
