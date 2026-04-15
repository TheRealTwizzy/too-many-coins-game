# Effective Config Audit

- Status: `fail`
- Simulator: `B`
- Seed: `coupling-lockin-expiry-a`
- Run Label: `season_balance-stage1-target_spend_rate_per_tick-05-candidate-lock_in_down_but_expiry_dominance_up-coupling_lockin_expiry-coupling-lockin-expiry-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.target_spend_rate_per_tick` => inactive_unreferenced
  requested=17 | effective=17 | source=candidate_patch
  detail=Key resolves correctly but the simulator does not read it during B/C execution.
