# Effective Config Audit

- Status: `fail`
- Simulator: `B`
- Seed: `tier1-concentration-a`
- Run Label: `season_recovery-verify-concentration_control-cand-3-tier1-tier1_concentration-tier1-concentration-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Validation

- `season.market_affordability_bias_fp` => candidate_out_of_range
  detail=Value must be <= 1000000.
- `season.market_affordability_bias_fp` => candidate_duplicate_key
  detail=Candidate change list may not set the same canonical key more than once.

## Candidate Changes

- `season.market_affordability_bias_fp` => inactive_shadowed
  requested=922082000000 | effective=849698563000000000 | source=candidate_patch
  detail=A higher-precedence layer resolved a different effective value.
- `season.market_affordability_bias_fp` => active
  requested=849698563000000000 | effective=849698563000000000 | source=candidate_patch
