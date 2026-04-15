# Effective Config Audit

- Status: `pass`
- Simulator: `C`
- Seed: `tier2-integration-a`
- Run Label: `lifetime_recovery-verify-sigil_loop-cand-4-tier2-tier2_integration-tier2-integration-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.market_affordability_bias_fp` => active
  requested=921500 | effective=921500 | source=candidate_patch
