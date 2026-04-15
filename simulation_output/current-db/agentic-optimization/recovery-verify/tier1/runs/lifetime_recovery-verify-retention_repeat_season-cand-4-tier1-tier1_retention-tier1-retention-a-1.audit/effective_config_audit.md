# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `tier1-retention-a`
- Run Label: `lifetime_recovery-verify-retention_repeat_season-cand-4-tier1-tier1_retention-tier1-retention-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.base_ubi_active_per_tick` => active
  requested=32 | effective=32 | source=candidate_patch
