# Effective Config Audit

- Status: `pass`
- Simulator: `promotion-preflight`
- Seed: `focused-single-hoarding-min-factor-70000`
- Run Label: `focused-single-hoarding-min-factor-70000-promotion-preflight`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_min_factor_fp` => active
  requested=70000 | effective=70000 | source=candidate_patch
