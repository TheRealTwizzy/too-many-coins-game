# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `tier1-blackout-a`
- Run Label: `lifetime_recovery-verify-blackout_lockin-cand-3-tier1-tier1_blackout-tier1-blackout-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.market_affordability_bias_fp` => active
  requested=940900 | effective=940900 | source=candidate_patch
