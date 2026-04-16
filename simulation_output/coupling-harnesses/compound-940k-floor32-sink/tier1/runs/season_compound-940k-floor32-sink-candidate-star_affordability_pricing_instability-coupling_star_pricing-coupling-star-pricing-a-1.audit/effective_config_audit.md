# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `coupling-star-pricing-a`
- Run Label: `season_compound-940k-floor32-sink-candidate-star_affordability_pricing_instability-coupling_star_pricing-coupling-star-pricing-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.market_affordability_bias_fp` => active
  requested=940000 | effective=940000 | source=candidate_patch
- `season.star_price_minimum_absolute` => active
  requested=32 | effective=32 | source=candidate_patch
- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
- `season.hoarding_safe_hours` => active
  requested=24 | effective=24 | source=candidate_patch
