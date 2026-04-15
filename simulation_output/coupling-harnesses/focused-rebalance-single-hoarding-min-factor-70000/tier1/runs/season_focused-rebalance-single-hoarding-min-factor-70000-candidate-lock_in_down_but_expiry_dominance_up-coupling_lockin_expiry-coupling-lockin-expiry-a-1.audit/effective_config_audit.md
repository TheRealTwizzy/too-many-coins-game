# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `coupling-lockin-expiry-a`
- Run Label: `season_focused-rebalance-single-hoarding-min-factor-70000-candidate-lock_in_down_but_expiry_dominance_up-coupling_lockin_expiry-coupling-lockin-expiry-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_min_factor_fp` => active
  requested=70000 | effective=70000 | source=candidate_patch
