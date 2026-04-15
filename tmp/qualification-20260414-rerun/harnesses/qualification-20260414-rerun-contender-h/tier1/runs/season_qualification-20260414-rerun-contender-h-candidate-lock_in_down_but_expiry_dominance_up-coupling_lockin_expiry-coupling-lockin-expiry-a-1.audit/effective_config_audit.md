# Effective Config Audit

- Status: `fail`
- Simulator: `B`
- Seed: `coupling-lockin-expiry-a`
- Run Label: `season_qualification-20260414-rerun-contender-h-candidate-lock_in_down_but_expiry_dominance_up-coupling_lockin_expiry-coupling-lockin-expiry-a-1`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.vault_config` => inactive_unreferenced
  requested="[{\"tier\":1,\"supply\":575,\"cost_table\":[{\"cost\":48,\"remaining\":1}]},{\"tier\":2,\"supply\":288,\"cost_table\":[{\"cost\":238,\"remaining\":1}]},{\"tier\":3,\"supply\":144,\"cost_table\":[{\"cost\":950,\"remaining\":1}]}]" | effective="[{\"tier\":1,\"supply\":575,\"cost_table\":[{\"cost\":48,\"remaining\":1}]},{\"tier\":2,\"supply\":288,\"cost_table\":[{\"cost\":238,\"remaining\":1}]},{\"tier\":3,\"supply\":144,\"cost_table\":[{\"cost\":950,\"remaining\":1}]}]" | source=candidate_patch
  detail=Key resolves correctly but the simulator does not read it during B/C execution.
