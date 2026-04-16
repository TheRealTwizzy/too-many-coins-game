# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `full-campaign-proof-20260414b|scenario|phase-gated-plus-inflation-tighten-v1|sim|C`
- Run Label: `sweep_phase-gated-plus-inflation-tighten-v1_C_full-campaign-proof-20260414b_scenario_phase-gated-plus-inflation-tighten-v1_sim_C_ppa2_s4`
- Base Season Source: `defaults_only`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
- `season.hoarding_safe_hours` => active
  requested=24 | effective=24 | source=candidate_patch
- `season.inflation_table` => active
  requested="[{\"x\": 0, \"factor_fp\": 1000000}, {\"x\": 50000, \"factor_fp\": 620000}, {\"x\": 200000, \"factor_fp\": 280000}, {\"x\": 800000, \"factor_fp\": 110000}, {\"x\": 3000000, \"factor_fp\": 50000}]" | effective="[{\"x\": 0, \"factor_fp\": 1000000}, {\"x\": 50000, \"factor_fp\": 620000}, {\"x\": 200000, \"factor_fp\": 280000}, {\"x\": 800000, \"factor_fp\": 110000}, {\"x\": 3000000, \"factor_fp\": 50000}]" | source=candidate_patch
- `season.base_ubi_active_per_tick` => active
  requested=36 | effective=36 | source=candidate_patch
- `season.base_ubi_idle_factor_fp` => active
  requested=220000 | effective=220000 | source=candidate_patch
