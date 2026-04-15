# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `tier1-expiry-a`
- Run Label: `season_recovery-verify-expiry_pressure-cand-2-tier1-tier1_expiry-tier1-expiry-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_downstep_fp` => active
  requested=14515 | effective=14515 | source=candidate_patch
