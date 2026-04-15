# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `qualification-proof-20260414b|scenario|phase-gated-safe-24h-v1|sim|C`
- Run Label: `sweep_phase-gated-safe-24h-v1_C_qualification-proof-20260414b_scenario_phase-gated-safe-24h-v1_sim_C_ppa2_s4`
- Base Season Source: `defaults_only`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
- `season.hoarding_safe_hours` => active
  requested=24 | effective=24 | source=candidate_patch
