# Qualification Report

Qualification window:
- Local date: `2026-04-14` (`America/Denver`)
- Artifact timestamps: `2026-04-15` (`UTC`)

Scope:
- close the promotion/comparator contradiction
- close the checked-in baseline artifact mismatch
- rerun focused qualification on the refreshed official baseline

## A. Root Cause of the Promotion/Comparator Contradiction

The promotion ladder and qualification were proving different things.

- `CandidatePromotionPipeline` previously allowed `promotion_eligible=true` after:
  - schema + preflight
  - targeted harnesses
  - single-season validation
  - Sim C lifetime metric checks
- It did **not** require the official `qualification` sweep/comparator profile before marking the candidate patch-ready.
- That meant a candidate could pass the local ladder even when the official B+C comparator would reject it with regression flags and rejection attribution.
- There was also a wrapper mismatch:
  - the promotion comparator stage used its own synthetic scenario name and derived seed
  - standalone qualification used a different scenario identity and seed
  - even when the candidate patch was the same, exact comparator outputs were harder to line up than they needed to be

## B. Root Cause of the Checked-In Baseline Artifact Mismatch

The checked-in canonical artifact had drifted away from the canonical exporter boundary.

- `simulation_output/current-db/export/current_season_economy_only.json` still contained deprecated key `starprice_model_version`.
- It was also stale on multiple canonical values relative to the tracked `simulation_output/current-db/export/current_season.json` snapshot.
- Strict preflight only accepts `SeasonConfigExporter::canonicalConfigKeys()`, so the stale artifact failed unchanged before simulation could start.
- The repo did not have a reproducible no-DB refresh path that regenerated the tracked canonical artifact through the exporter itself, and it did not have a test asserting artifact/preflight alignment.

## C. Exact Fixes Made

### 1. Promotion readiness

- Added `scripts/simulation/PromotionReadinessGate.php`.
  - canonical rule: promotion can proceed only when the official comparator result is `non-reject` and regression flags are empty
- Added required stage `official_qualification_comparator_validation` to `CandidatePromotionPipeline`.
  - it runs `SweepComparatorCampaignRunner` with profile `qualification`
  - it blocks eligibility before patch/parity/compat stages when the official comparator rejects
- Normalized the promotion comparator wrapper:
  - scenario name now reuses `candidate_id`
  - comparator seed now reuses the outer pipeline seed
  - this lets promotion-stage comparator output line up exactly with standalone qualification when the same seed/candidate identity are used
- Updated operator docs/help:
  - `scripts/promote_simulation_candidate.php`
  - `SIMULATION_RUNBOOK.md`

### 2. Baseline artifact refresh

- Extended `tools/export-season-config.php` with `--input-json=FILE`.
  - this allows canonical export refresh from a tracked season snapshot when DB export is unavailable
- Updated `tools/Invoke-TmcSimulationStep.ps1` to use the official canonical output path:
  - `simulation_output/current-db/export/current_season_economy_only.json`
- Refreshed the checked-in artifact canonically:

```powershell
php tools/export-season-config.php `
  --input-json=simulation_output/current-db/export/current_season.json `
  --output=simulation_output/current-db/export/current_season_economy_only.json
```

### 3. Tests added/updated

- `tests/OfficialBaselineArtifactTest.php`
  - checked-in official baseline passes unchanged
  - forbidden metadata/runtime fields are excluded
  - checked-in artifact matches exporter projection of the tracked snapshot
- `tests/PromotionReadinessGateTest.php`
  - comparator-rejected candidate cannot become promotion-eligible
  - genuinely passing candidate can still become promotion-eligible
  - promotion and qualification use the same comparator gate semantics
- `tests/CandidatePromotionPipelineTest.php`
  - kept the fast blocking proof that failed early stages still stop later stages

## D. Evidence That Promotion Eligibility and Comparator Outcomes Are Now Aligned

### Official qualification rerun on refreshed baseline

Artifact:
- `tmp/focused-official-qualification/comparator/comparison_focused-official-qualification.json`

Result:
- scenario: `phase-gated-safe-24h-v1`
- disposition: `reject`
- regression flags:
  - `dominant_archetype_shifted`
  - `long_run_concentration_worsened`
  - `seasonal_fairness_improves_but_long_run_concentration_worsens`

### Promotion ladder rerun on the same seed/scenario identity

Artifacts:
- `tmp/focused-safe24-aligned/phase-gated-safe-24h-v1/promotion_state.json`
- `tmp/focused-safe24-aligned/phase-gated-safe-24h-v1/06_official_qualification_comparator_validation/campaign/comparator/comparison_focused-official-qualification.json`
- `tmp/focused-promotion-comparator-alignment.json`

Result:
- candidate: `phase-gated-safe-24h-v1`
- seed: `focused-official-qualification`
- early ladder stages 1-5 still passed
- official comparator stage 6 failed
- `promotion_eligible=false`

Alignment proof:
- standalone qualification disposition: `reject`
- promotion-stage comparator disposition: `reject`
- standalone qualification regression flags: identical
- promotion-stage comparator regression flags: identical

This closes the original contradiction: the ladder no longer marks the candidate promotion-ready once the official comparator says reject.

### Additional readiness probe

Artifact:
- `tmp/focused-clean-baseline-equivalent/focused-clean-baseline-equivalent/promotion_report.json`

Result:
- even a baseline-equivalent single-knob candidate now gets blocked when the official comparator rejects it
- this is expected under the new canonical rule and confirms the promotion decision is no longer bypassing qualification evidence

## E. Evidence That the Checked-In Official Baseline Artifact Passes Unchanged

Artifact:
- `simulation_output/current-db/export/current_season_economy_only.json`

Evidence:
- `OfficialBaselineArtifactTest::testOfficialBaselineArtifactPassesPreflightUnchanged`
- `OfficialBaselineArtifactTest::testOfficialBaselineArtifactExcludesForbiddenMetadataAndRuntimeFields`
- `OfficialBaselineArtifactTest::testCheckedInCanonicalArtifactMatchesTrackedSnapshotProjection`

Observed state after canonical refresh:
- `starprice_model_version` is absent
- metadata keys are absent
- runtime-only keys are absent
- the file exactly matches `SeasonConfigExporter::canonicalConfigFromRow()` applied to the tracked `current_season.json` snapshot

## F. Focused Qualification Checks

### 1. Checked-in official baseline artifact passes official preflight unchanged

- `pass`

### 2. Disabled-subsystem suppression still passes

Evidence:
- `SimulationConfigPreflightTest::testFeatureDisabledButKnobChangedFailsPreflight`

Result:
- `pass`
- reason code still resolves to `candidate_disabled_subsystem`

### 3. Determinism still passes

Artifact:
- `tmp/focused-determinism-summary.json`

Result:
- single-season archetypes: identical
- single-season diagnostics: identical
- lifetime players: identical
- lifetime population diagnostics: identical
- status: `pass`

### 4. Rejection attribution/comparator still works

Artifacts:
- `tmp/focused-official-qualification/comparator/comparison_focused-official-qualification.json`
- `tmp/focused-official-qualification/comparator/rejections/focused-official-qualification/phase-gated-safe-24h-v1/rejection_attribution.json`

Result:
- `pass`
- rejection attribution was generated with a concrete primary failed gate and secondary regressions

### 5. Promotion ladder and comparator now agree on candidate readiness

- `pass`
- standalone qualification and the promotion stage both reject `phase-gated-safe-24h-v1`
- promotion no longer reaches `promotion_eligible=true`

### 6. Parity certification and deterministic patch generation still work for truly eligible candidates

Component evidence:
- `RuntimeParityCertificationTest`
- `PromotionPatchGeneratorTest`

Result:
- the component mechanisms still pass
- but no comparator-approved promotion candidate was found in this focused rerun, so the full end-to-end eligible-candidate promotion path was **not** demonstrated

## G. Residual Risks

- No truly eligible candidate was found on the refreshed official baseline under the official `qualification` comparator profile.
- Because no candidate cleared the comparator gate, the full eligible promotion flow after stage 6 remains unproven in this rerun.
- DB-backed exporter execution was unavailable in this workspace, so the canonical baseline was refreshed from the tracked snapshot via `--input-json` rather than from a live DB row.

## Final Verdict

`not ready`

Why:
- the two original blockers are fixed:
  - the checked-in official baseline artifact is now canonical and passes unchanged
  - promotion can no longer bypass the official comparator
- but the refreshed suite still does not produce a truly eligible comparator-approved candidate in focused qualification
- that means controlled promotion readiness is still not demonstrated end-to-end
