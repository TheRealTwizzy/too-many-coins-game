# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `tier1-sigil-a`
- Run Label: `season_recovery-verify-sigil_loop-cand-2-tier1-tier1_sigil-tier1-sigil-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.starprice_max_upstep_fp` => active
  requested=880 | effective=880 | source=candidate_patch
