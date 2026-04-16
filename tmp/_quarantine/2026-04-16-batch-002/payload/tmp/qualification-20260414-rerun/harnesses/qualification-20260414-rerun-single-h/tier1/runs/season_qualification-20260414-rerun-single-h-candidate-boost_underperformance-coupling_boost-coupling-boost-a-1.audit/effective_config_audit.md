# Effective Config Audit

- Status: `pass`
- Simulator: `B`
- Seed: `coupling-boost-a`
- Run Label: `season_qualification-20260414-rerun-single-h-candidate-boost_underperformance-coupling_boost-coupling-boost-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.base_ubi_active_per_tick` => active
  requested=32 | effective=32 | source=candidate_patch
