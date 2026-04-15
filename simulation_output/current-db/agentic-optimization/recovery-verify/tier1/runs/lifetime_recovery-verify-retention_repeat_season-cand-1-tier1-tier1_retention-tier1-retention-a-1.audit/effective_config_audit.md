# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `tier1-retention-a`
- Run Label: `lifetime_recovery-verify-retention_repeat_season-cand-1-tier1-tier1_retention-tier1-retention-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_min_factor_fp` => active
  requested=94500 | effective=94500 | source=candidate_patch
