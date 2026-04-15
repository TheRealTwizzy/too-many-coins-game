# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `tier1-boost-a`
- Run Label: `season_recovery-verify-boost_viability-cand-2-tier1-tier1_boost-tier1-boost-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_min_factor_fp` => active
  requested=99000 | effective=99000 | source=candidate_patch
