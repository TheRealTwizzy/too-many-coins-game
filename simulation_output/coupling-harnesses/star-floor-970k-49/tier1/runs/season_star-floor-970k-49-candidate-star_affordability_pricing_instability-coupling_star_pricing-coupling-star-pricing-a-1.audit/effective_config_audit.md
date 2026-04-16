# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `coupling-star-pricing-a`
- Run Label: `season_star-floor-970k-49-candidate-star_affordability_pricing_instability-coupling_star_pricing-coupling-star-pricing-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.market_affordability_bias_fp` => active
  requested=970000 | effective=970000 | source=candidate_patch
- `season.star_price_minimum_absolute` => active
  requested=49 | effective=49 | source=candidate_patch
