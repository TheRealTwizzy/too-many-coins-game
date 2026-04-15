# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `full-campaign-proof-20260414b|scenario|phase-gated-high-floor-v1|sim|B`
- Run Label: `sweep_phase-gated-high-floor-v1_B_full-campaign-proof-20260414b_scenario_phase-gated-high-floor-v1_sim_B_ppa2`
- Base Season Source: `defaults_only`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
- `season.hoarding_safe_hours` => active
  requested=24 | effective=24 | source=candidate_patch
- `season.hoarding_safe_min_coins` => active
  requested=40000 | effective=40000 | source=candidate_patch
