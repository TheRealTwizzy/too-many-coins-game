# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `tier1-lockin-a`
- Run Label: `season_recovery-verify-lockin_incentives-cand-3-tier1-tier1_lockin-tier1-lockin-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_downstep_fp` => active
  requested=13738 | effective=13738 | source=candidate_patch
