# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `coupling-star-pricing-a`
- Run Label: `season_focused-rebalance-pair-starprice950-hoardingmin70000-candidate-star_affordability_pricing_instability-coupling_star_pricing-coupling-star-pricing-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=950 | effective=950 | source=candidate_patch
- `season.hoarding_min_factor_fp` => active
  requested=70000 | effective=70000 | source=candidate_patch
