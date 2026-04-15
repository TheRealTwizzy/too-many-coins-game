# Qualification Plan

Qualification window:
- Local date: `2026-04-14` (`America/Denver`)
- Artifact timestamps: `2026-04-15` (`UTC`)

Objective:
- Close the two remaining critical blockers:
  - promotion eligibility must not bypass the official qualification comparator standard
  - the checked-in official baseline artifact must pass strict preflight unchanged
- Rerun a focused readiness qualification pass against the refreshed canonical baseline

Root-cause focus:
1. Promotion/comparator contradiction
   - inspect `CandidatePromotionPipeline`
   - inspect the official `qualification` profile in `SweepComparatorProfileCatalog`
   - align promotion readiness to the official comparator gate
2. Checked-in baseline artifact mismatch
   - inspect `SeasonConfigExporter`, `SimulationConfigPreflight`, and the checked-in `simulation_output/current-db/export/current_season_economy_only.json`
   - repair the refresh path so the tracked canonical artifact is generated through the exporter boundary

Canonical fixes to apply:
1. Promotion readiness
   - add a required promotion stage that runs the official `qualification` sweep/comparator profile
   - block `promotion_eligible=true` unless the comparator stage is non-reject and carries zero regression flags
   - normalize the promotion comparator wrapper so scenario identity and seed can match standalone qualification runs
2. Baseline artifact validity
   - extend `tools/export-season-config.php` with snapshot-input refresh support via `--input-json=FILE`
   - make the current-db helper export the official canonical artifact path `simulation_output/current-db/export/current_season_economy_only.json`
   - regenerate the checked-in canonical artifact through the exporter, not by hand

Focused verification steps:
1. Run fast proof tests:
   - `OfficialBaselineArtifactTest`
   - `PromotionReadinessGateTest`
   - `CandidatePromotionPipelineTest`
   - targeted `SimulationConfigPreflightTest`
   - `RuntimeParityCertificationTest`
   - `PromotionPatchGeneratorTest`
2. Verify the checked-in baseline artifact passes strict preflight unchanged.
3. Verify disabled-subsystem suppression still rejects inactive/shadowed candidate keys.
4. Verify deterministic single-season and lifetime simulation outputs on the refreshed official baseline.
5. Rerun the official `qualification` comparator profile against `simulation_output/current-db/export/current_season_economy_only.json`.
6. Rerun the promotion ladder on the pinned `phase-gated-safe-24h-v1` candidate using the same seed/scenario identity as standalone qualification and confirm the promotion comparator stage matches the official comparator outcome.
7. Probe for at least one truly eligible promotion candidate.

Important constraint handling:
- No meaningful comparator gates are removed.
- Promotion eligibility cannot bypass comparator-equivalent rejection checks.
- The checked-in baseline artifact is refreshed only through the canonical exporter boundary.
- DB-backed exporter execution was not available in this workspace, so snapshot-input refresh is used as the reproducible fallback.

Expected decision rule:
- `ready for controlled use` only if the refreshed baseline passes unchanged, comparator/promotion alignment is proven, and at least one truly eligible candidate can clear the full readiness path.
- `ready for simulation only` only if the suite is operational for analysis but still lacks promotion-ready evidence.
- `not ready` if the official qualification profile still rejects the pinned candidate set or no truly eligible promotion path can be demonstrated.
