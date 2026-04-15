# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `tier1-onboarding-a`
- Run Label: `season_recovery-verify-onboarding_economy-cand-3-tier1-tier1_onboarding-tier1-onboarding-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.base_ubi_idle_factor_fp` => active
  requested=260000 | effective=260000 | source=candidate_patch
