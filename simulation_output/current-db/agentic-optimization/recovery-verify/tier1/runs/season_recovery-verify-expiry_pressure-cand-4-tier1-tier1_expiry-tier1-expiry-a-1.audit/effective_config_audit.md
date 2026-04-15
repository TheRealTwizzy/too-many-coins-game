# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `tier1-expiry-a`
- Run Label: `season_recovery-verify-expiry_pressure-cand-4-tier1-tier1_expiry-tier1-expiry-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_min_factor_fp` => active
  requested=97200 | effective=97200 | source=candidate_patch
