# Play-Test Runtime Parity Certification

`RuntimeParityCertification` is the promotion-critical proof that the simulator and the play-test runtime still agree on tuned mechanics before a config is promoted.

## Covered Domains

- `hoarding_sink_behavior`
- `boost_behavior`
- `lock_in_timing`
- `expiry_timing`
- `star_pricing_affordability`
- `rejoin_participation_effects`
- `blackout_finalization_interactions`

Every certification run compares simulator outputs against play-test runtime outputs for identical fixture inputs and fails on any non-tolerated drift.

## Run It

Standalone:

```powershell
php scripts/certify_runtime_parity.php `
  --candidate-id=current-db `
  --season-config=simulation_output/current-db/export/current_season.json `
  --output=simulation_output/runtime-parity/current-db
```

Promotion pipeline:

- stage `7` is now `play_test_runtime_parity_certification`
- stage `8` remains `play_test_repo_compatibility_validation`
- promotion eligibility is only marked after stages `1-8` pass

## Report Artifacts

Each run writes:

- `runtime_parity_certification.json`
- `runtime_parity_certification.md`

JSON schema version:

- `tmc-runtime-parity-certification.v1`

Top-level fields:

- `candidate_id`
- `seed`
- `season_surface_sha256`
- `certification_status`
- `certified`
- `required_domain_ids`
- `material_drift_count`
- `tolerated_difference_count`
- `tolerated_differences`
- `domains[]`

Per-domain fields:

- `domain_id`
- `label`
- `status`
- `fixture_count`
- `material_drift_count`
- `tolerated_difference_count`
- `tolerated_differences`
- `fixtures[]`

Per-fixture fields:

- `fixture_id`
- `label`
- `status`
- `description`
- `tolerated_differences`
- `simulator_output`
- `runtime_output`
- `metrics[]`

Per-metric fields:

- `path`
- `tolerance`
- `simulator`
- `runtime`
- `status`

## Material Drift Rule

Certification fails when any compared metric differs beyond its configured tolerance and that metric is not explicitly tolerated.

Default tolerances in the current fixture set are strict:

- integer and boolean mechanic fields must match exactly
- no drift is allowed for hoarding sink totals, boost expiry windows, lock-in gating, expiry payout fields, price usage, rejoin resets, or blackout/finalization mechanics

## Explicitly Tolerated Differences

These are documented in every certification report:

- transport-only side effects are ignored: notification rows, SQL timestamps, auto-increment ids, and API envelope formatting are not mechanic drift
- boost certification covers deterministic purchase, accrual, and expiry semantics, not client countdown rendering or HTTP/session transport
- rejoin certification compares run-affecting state only, not exact timestamp formatting metadata
