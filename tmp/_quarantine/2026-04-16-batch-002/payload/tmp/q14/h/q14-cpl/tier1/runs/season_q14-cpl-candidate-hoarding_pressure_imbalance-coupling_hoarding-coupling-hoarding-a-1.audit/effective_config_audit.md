# Effective Config Audit

- Status: `fail`
- Simulator: `B`
- Seed: `coupling-hoarding-a`
- Run Label: `season_q14-cpl-candidate-hoarding_pressure_imbalance-coupling_hoarding-coupling-hoarding-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.hoarding_sink_enabled` => active
  requested=1 | effective=1 | source=candidate_patch
- `season.hoarding_idle_multiplier_fp` => active
  requested=1339000 | effective=1339000 | source=candidate_patch
- `season.hoarding_tier2_rate_hourly_fp` => active
  requested=578 | effective=578 | source=candidate_patch
- `season.market_affordability_bias_fp` => inactive_unreferenced
  requested=873000 | effective=873000 | source=candidate_patch
  detail=Key resolves correctly but the simulator does not read it during B/C execution.
