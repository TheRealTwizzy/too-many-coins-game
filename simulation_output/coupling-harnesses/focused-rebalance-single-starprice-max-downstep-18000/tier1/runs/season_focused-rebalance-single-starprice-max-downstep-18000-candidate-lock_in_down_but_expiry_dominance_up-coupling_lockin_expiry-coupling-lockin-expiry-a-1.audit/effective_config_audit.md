# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `coupling-lockin-expiry-a`
- Run Label: `season_focused-rebalance-single-starprice-max-downstep-18000-candidate-lock_in_down_but_expiry_dominance_up-coupling_lockin_expiry-coupling-lockin-expiry-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_downstep_fp` => active
  requested=18000 | effective=18000 | source=candidate_patch
