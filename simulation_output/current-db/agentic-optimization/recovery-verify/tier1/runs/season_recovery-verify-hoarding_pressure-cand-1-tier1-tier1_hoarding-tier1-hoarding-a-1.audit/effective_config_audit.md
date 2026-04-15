# Effective Config Audit

- Status: `fail`
- Simulator: `B`
- Seed: `tier1-hoarding-a`
- Run Label: `season_recovery-verify-hoarding_pressure-cand-1-tier1-tier1_hoarding-tier1-hoarding-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Validation

- `season.hoarding_tier2_rate_hourly_fp` => candidate_disabled_subsystem
  detail=Subsystem `hoarding_preservation_pressure` is disabled for this candidate context because `season.hoarding_sink_enabled` resolves to 0.

## Candidate Changes

- `season.hoarding_tier2_rate_hourly_fp` => inactive_feature_disabled
  requested=556 | effective=556 | source=candidate_patch
  detail=season.hoarding_sink_enabled resolves to 0.
