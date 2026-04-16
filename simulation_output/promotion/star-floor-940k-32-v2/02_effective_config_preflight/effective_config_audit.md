# Effective Config Audit

- Status: `pass`
- Simulator: `promotion-preflight`
- Seed: `star-floor-940k-32-v2`
- Run Label: `star-floor-940k-32-v2-promotion-preflight`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.market_affordability_bias_fp` => active
  requested=940000 | effective=940000 | source=candidate_patch
- `season.star_price_minimum_absolute` => active
  requested=32 | effective=32 | source=candidate_patch
