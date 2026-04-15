# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `tier2-integration-a`
- Run Label: `lifetime_recovery-verify-boost_viability-cand-4-tier2-tier2_integration-tier2-integration-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.base_ubi_active_per_tick` => active
  requested=33 | effective=33 | source=candidate_patch
