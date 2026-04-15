# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `tier1-concentration-a`
- Run Label: `season_recovery-verify-concentration_control-cand-1-tier1-tier1_concentration-tier1-concentration-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.market_affordability_bias_fp` => active
  requested=950600 | effective=950600 | source=candidate_patch
