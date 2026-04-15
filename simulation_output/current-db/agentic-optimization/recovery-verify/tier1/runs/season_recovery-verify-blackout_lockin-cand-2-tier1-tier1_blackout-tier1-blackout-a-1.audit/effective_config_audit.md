# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `tier1-blackout-a`
- Run Label: `season_recovery-verify-blackout_lockin-cand-2-tier1-tier1_blackout-tier1-blackout-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=850 | effective=850 | source=candidate_patch
