# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `coupling-lockin-expiry-a`
- Run Label: `season_recovery-verify-expiry_pressure-cand-4-coupling-candidate-lock_in_down_but_expiry_dominance_up-coupling_lockin_expiry-coupling-lockin-expiry-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_min_factor_fp` => active
  requested=97200 | effective=97200 | source=candidate_patch
