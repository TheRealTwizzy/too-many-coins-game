# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `star-floor-940k-32-v2|scenario|star-floor-940k-32-v2|sim|C`
- Run Label: `sweep_star-floor-940k-32-v2_C_star-floor-940k-32-v2_scenario_star-floor-940k-32-v2_sim_C_ppa2_s4`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.market_affordability_bias_fp` => active
  requested=940000 | effective=940000 | source=candidate_patch
- `season.star_price_minimum_absolute` => active
  requested=32 | effective=32 | source=candidate_patch
