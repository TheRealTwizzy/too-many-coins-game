# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `focused-clean-baseline-equivalent|promotion|qualification|scenario|promotion-ready-focused-clean-baseline-equivalent|sim|B`
- Run Label: `sweep_promotion-ready-focused-clean-baseline-equivalent_B_focused-clean-baseline-equivalent_promotion_qualification_scenario_promotion-ready-focused-clean-baseline-equivalent_sim_B_ppa2`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.base_ubi_active_per_tick` => active
  requested=30 | effective=30 | source=candidate_patch
