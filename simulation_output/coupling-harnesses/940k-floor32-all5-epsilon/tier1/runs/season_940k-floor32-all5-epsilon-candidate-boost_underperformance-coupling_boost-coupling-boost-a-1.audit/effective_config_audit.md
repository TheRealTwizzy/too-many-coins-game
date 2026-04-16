# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `coupling-boost-a`
- Run Label: `season_940k-floor32-all5-epsilon-candidate-boost_underperformance-coupling_boost-coupling-boost-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.market_affordability_bias_fp` => active
  requested=940000 | effective=940000 | source=candidate_patch
- `season.star_price_minimum_absolute` => active
  requested=32 | effective=32 | source=candidate_patch
