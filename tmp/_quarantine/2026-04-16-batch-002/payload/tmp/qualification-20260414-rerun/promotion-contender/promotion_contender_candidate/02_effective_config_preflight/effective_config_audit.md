# Effective Config Audit

- Status: `fail`
- Simulator: `promotion-preflight`
- Seed: `qualification-20260414-rerun-contender`
- Run Label: `promotion_contender_candidate-promotion-preflight`
- Base Season Source: `inline`

## Precedence

- Season: simulation_defaults < base_season_override < candidate_patch < scenario_override
- Runtime: code_default < environment

## Candidate Changes

- `season.vault_config` => inactive_unreferenced
  requested="[{\"tier\":1,\"supply\":575,\"cost_table\":[{\"cost\":48,\"remaining\":1}]},{\"tier\":2,\"supply\":288,\"cost_table\":[{\"cost\":238,\"remaining\":1}]},{\"tier\":3,\"supply\":144,\"cost_table\":[{\"cost\":950,\"remaining\":1}]}]" | effective="[{\"tier\":1,\"supply\":575,\"cost_table\":[{\"cost\":48,\"remaining\":1}]},{\"tier\":2,\"supply\":288,\"cost_table\":[{\"cost\":238,\"remaining\":1}]},{\"tier\":3,\"supply\":144,\"cost_table\":[{\"cost\":950,\"remaining\":1}]}]" | source=candidate_patch
  detail=Key resolves correctly but the simulator does not read it during B/C execution.
