# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `tier1-star-pricing-a`
- Run Label: `season_recovery-verify-star_pricing-cand-4-tier1-tier1_star_pricing-tier1-star-pricing-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_downstep_fp` => active
  requested=14256 | effective=14256 | source=candidate_patch
