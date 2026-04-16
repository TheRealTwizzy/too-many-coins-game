# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `qualification-20260414-rerun-det|scenario|phase-gated-safe-24h-v1|sim|B`
- Run Label: `sweep_phase-gated-safe-24h-v1_B_qualification-20260414-rerun-det_scenario_phase-gated-safe-24h-v1_sim_B_ppa2`
- Base Season Source: `file`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
- `season.hoarding_safe_hours` => active
  requested=24 | effective=24 | source=candidate_patch
