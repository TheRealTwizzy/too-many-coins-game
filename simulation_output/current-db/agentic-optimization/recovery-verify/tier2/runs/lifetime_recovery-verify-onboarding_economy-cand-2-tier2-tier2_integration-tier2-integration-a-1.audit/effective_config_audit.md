# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `tier2-integration-a`
- Run Label: `lifetime_recovery-verify-onboarding_economy-cand-2-tier2-tier2_integration-tier2-integration-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.base_ubi_active_per_tick` => active
  requested=34 | effective=34 | source=candidate_patch
