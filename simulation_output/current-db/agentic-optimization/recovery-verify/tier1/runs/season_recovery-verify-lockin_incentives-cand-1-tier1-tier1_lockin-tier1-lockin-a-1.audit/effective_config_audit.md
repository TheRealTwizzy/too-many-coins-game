# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `tier1-lockin-a`
- Run Label: `season_recovery-verify-lockin_incentives-cand-1-tier1-tier1_lockin-tier1-lockin-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.market_affordability_bias_fp` => active
  requested=940900 | effective=940900 | source=candidate_patch
