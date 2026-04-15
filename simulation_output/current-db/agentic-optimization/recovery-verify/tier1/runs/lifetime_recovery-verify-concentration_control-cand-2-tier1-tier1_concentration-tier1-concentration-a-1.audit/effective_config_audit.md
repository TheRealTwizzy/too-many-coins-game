# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `tier1-concentration-a`
- Run Label: `lifetime_recovery-verify-concentration_control-cand-2-tier1-tier1_concentration-tier1-concentration-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.market_affordability_bias_fp` => active
  requested=921500 | effective=921500 | source=candidate_patch
