# Qualification Report

Qualification date: `2026-04-14`
Git commit: `4936633dabe17f64e58b672f71b3a9b01fc6772a`
Workspace: `tmp/qualification-20260414-rerun`

## 1. Baselines, Bundles, And Commands Used

- Official baseline artifact under test:
  - `simulation_output/current-db/export/current_season_economy_only.json`
- Diagnostic continuation baseline:
  - `tmp/qualification-20260414-rerun/diagnostic_clean_baseline.json`
  - Produced with `SeasonConfigExporter::canonicalConfigFromRow()` from the checked-in season row only after the official baseline artifact had already failed qualification
- Scenario bundle:
  - `simulation_output/sweep/followup-tuning-candidates-20260413.json`
- Seed bundle root:
  - `qualification-20260414-rerun`
- Promotion ladder size:
  - `players_per_archetype=2`
  - `season_count=4`

Canonical entrypoints confirmed in the repo:

- Baseline export: `php tools/export-season-config.php --output=FILE --metadata-output=FILE`
- Candidate linting: `php scripts/lint_candidate_packages.php --input=FILE --season-config=FILE`
- Effective-config preflight: `scripts/simulation/SimulationConfigPreflight.php`
- Staged generation: `php scripts/generate_tuning_candidates.php --diagnosis=... --season-config=... --output=... --version=3`
- Rejection attribution: `php scripts/compare_simulation_results.php --seed=... --sweep-manifest=FILE --output=...`
- Targeted harnesses: `php scripts/simulate_coupling_harnesses.php --seed=... --season-config=FILE --candidate-patch=FILE`
- Promotion ladder: `php scripts/promote_simulation_candidate.php --candidate=FILE --candidate-id=ID --seed=... --season-config=FILE --output=... --players-per-archetype=2 --season-count=4`
- Canonical config export: `php tools/export-season-config.php --output=FILE --metadata-output=FILE`
- Deterministic patch generation: `php scripts/generate_promotion_patch.php --promotion-report=FILE --output=DIR --dry-run`
- Parity certification: `php scripts/certify_runtime_parity.php --candidate-id=ID --seed=... --season-config=FILE --output=DIR`
- Qualification report generation: no dedicated script is present

Environment note:

- The DB-backed exporter CLI could not be run in this workspace because the configured DB connection was refused.
- Because of that, exporter behavior was verified through focused tests and through the checked-in export artifacts.

## 2. Qualification Candidate Set

- Official baseline artifact:
  - `simulation_output/current-db/export/current_season_economy_only.json`
  - Expected: pass official preflight unchanged

- `invalid_disabled_subsystem`
  - `tmp/qualification-20260414-rerun/candidates/invalid_disabled_subsystem.json`
  - Patch: `{"hoarding_safe_hours":24}`
  - Expected: fail preflight

- Suppressed disabled-subsystem family:
  - `phase_dead_zones -> hoarding_window_ticks`
  - Expected: suppressed at generation time, not emitted

- `single_knob_candidate`
  - `tmp/qualification-20260414-rerun/candidates/single_knob_candidate.json`
  - Patch: `{"base_ubi_active_per_tick":32}`
  - Expected: valid single-knob candidate

- `late_stage_failure_candidate`
  - `tmp/qualification-20260414-rerun/candidates/late_stage_failure_candidate.json`
  - Patch: `{"hoarding_sink_enabled":1,"hoarding_safe_hours":24}`
  - Expected: pass early stages, then fail later validation

- `promotion_contender_candidate`
  - `tmp/qualification-20260414-rerun/candidates/promotion_contender_candidate.json`
  - Patch: generated `vault_config` candidate
  - Expected: best available contender

## 3. Actual Outcomes By Area

### A. Export / Preflight Integrity

Result: `fail`

Evidence:

- The checked-in official baseline artifact failed the official preflight path unchanged:
  - `php scripts/simulate_economy.php --seed=qualification-20260414-rerun-baseline --players-per-archetype=1 --season-config=simulation_output/current-db/export/current_season_economy_only.json ...`
  - Failure: `Unknown base season config key: starprice_model_version`
- The official `qualification` sweep/comparator profile also failed immediately against the same artifact with the same error.
- Contract-surface comparison showed:
  - canonical contract keys: `27`
  - checked-in baseline keys: `28`
  - extra key: `starprice_model_version`
- The legacy export file `simulation_output/current-db/export/current_season.json` still contains DB/runtime fields such as `season_id`, `status`, and `created_at`.

Counter-evidence:

- `SeasonConfigExporterTest` passed and proves the exporter code now separates canonical config from metadata/runtime state.
- A diagnostic canonical baseline generated through `SeasonConfigExporter::canonicalConfigFromRow()` passed preflight and allowed the rest of qualification to continue.

Assessment:

- Exporter code looks fixed.
- The reproducible checked-in official baseline artifact is still not compatible with official preflight.
- That means the blocker is not closed end-to-end in repo-usable qualification evidence.

### B. Baseline-Aware Staged Generation

Result: `pass`

Evidence:

- Fresh staged generation against the disabled-hoarding baseline produced:
  - `3` candidates
  - `8` suppressed candidate families
- The previously problematic disabled family was suppressed instead of emitted:
  - family: `phase_dead_zones`
  - target: `hoarding_window_ticks`
  - reason: `subsystem_disabled_in_baseline`
- Suppression is visible in:
  - `tmp/qualification-20260414-rerun/staged-candidates/tuning_candidates_v3.json`
  - `tmp/qualification-20260414-rerun/staged-candidates/tuning_candidates_v3.md`

Assessment:

- The staged generator is now baseline-aware in the way qualification requires.
- Disabled-subsystem families are no longer leaking into generated candidates on this baseline.

### C. Candidate Validation And Harness Screening

Result: `mixed`

Candidate lint:

- `invalid_disabled_subsystem`: fail
  - reason code: `candidate_disabled_subsystem`
- `single_knob_candidate`: pass
- `late_stage_failure_candidate`: pass
- `promotion_contender_candidate`: pass

Promotion ladder outcomes on the diagnostic continuation baseline:

- `invalid_disabled_subsystem`
  - stage 1 fail: `candidate_disabled_subsystem`
  - later stages correctly blocked

- `single_knob_candidate`
  - stage 1 pass
  - stage 2 pass
  - stage 3 fail: targeted subsystem harnesses
  - later stages correctly blocked

- `promotion_contender_candidate`
  - stage 1 pass
  - stage 2 fail: `season.vault_config (inactive_unreferenced)`
  - later stages correctly blocked

- `late_stage_failure_candidate`
  - stages 1-9 all pass
  - marked `promotion_eligible=true`

Standalone harness evidence:

- `single_knob_candidate`: `fail`
  - failed families:
    - `skip_rejoin_exploit_worsened`
    - `hoarding_pressure_imbalance`
    - `star_affordability_pricing_instability`
- `late_stage_failure_candidate`: `pass`
- `promotion_contender_candidate`: harness run failed honestly during preflight because `vault_config` is `inactive_unreferenced`

Assessment:

- Stage gating is being enforced.
- Harness artifacts are real and informative.
- Unknown/inactive/shadowed behavior is not being silently ignored.

### D. Determinism

Result: `pass`

Evidence:

- Two equivalent Simulation D sweep runs with the same seed and different output roots normalized identically:
  - result envelope equality: `true`
  - per-run payload equality: `true` for all `4` baseline/scenario simulator pairs
- A real semantic change still diverged:
  - control: `players_per_archetype=2`
  - drift run: `players_per_archetype=3`
  - semantic drift detected: `true`
- Summary artifact:
  - `tmp/qualification-20260414-rerun/determinism_summary.json`

Assessment:

- The run-specific artifact-path determinism blocker appears closed.

### E. Rejection Attribution / Comparator Evidence

Result: `pass`

Evidence:

- The official supported `qualification` comparator profile completed successfully on the diagnostic continuation baseline:
  - duration: `3.07` minutes
  - completion status: `within-envelope`
  - sweep runs: `4`
  - rejected scenarios: `1`
- The rejection-attribution artifact was generated and usable:
  - `tmp/qualification-20260414-rerun/sweep-diagnostic-baseline/comparator/rejections/qualification-20260414-rerun-diagnostic-profile/phase-gated-safe-24h-v1/rejection_attribution.json`
- The rejected scenario carried specific flags:
  - `candidate_improves_B_but_worsens_C`
  - `dominant_archetype_shifted`
  - `reduced_one_dominant_but_created_new_dominant`
  - `skip_rejoin_exploit_worsened`

Assessment:

- The official qualification-scale comparator path is operational when fed a truly canonical baseline.
- Rejection attribution is no longer the weak point.

### F. Promotion Ladder / Parity / Patching

Result: `fail`

What worked:

- Parity certification for the eligible candidate passed:
  - standalone parity: `pass`
  - material drift count: `0`
- Play-test compatibility validation for the eligible candidate passed.
- Patch generation for the eligible candidate was deterministic:
  - bundle JSON hashes matched
  - diff hashes matched
  - staged SQL hashes matched
- Generated patch touched only one approved root migration file:
  - `migration_z_sim_promotion_late_stage_failure_candidate_f0d7aac9a64b_test_reset.sql`
- Patch generation failed honestly on an ineligible promotion report.

Critical mismatch:

- The same `late_stage_failure_candidate` that the promotion ladder marked `promotion_eligible=true` was rejected by the official qualification comparator profile.
- Promotion stage 5 is not the same proof as the official comparator path:
  - code inspection shows stage 5 only runs a direct Sim C lifetime comparison
  - it does not run the official B+C supported comparator profile
- That lets a candidate become patch-ready even when the official qualification comparator rejects it.

Assessment:

- Individual promotion components are behaving honestly.
- The promotion decision path is not aligned with the official qualification comparator.
- That is a critical blocker for balance-suggestion workflows.

## 4. Failures By Reason Code / Gate

- `candidate_disabled_subsystem`
  - `invalid_disabled_subsystem`
- `inactive_unreferenced`
  - `promotion_contender_candidate`
- targeted harness regressions
  - `single_knob_candidate`
  - failed families:
    - `skip_rejoin_exploit_worsened`
    - `hoarding_pressure_imbalance`
    - `star_affordability_pricing_instability`
- official comparator rejection flags for `phase-gated-safe-24h-v1`
  - `candidate_improves_B_but_worsens_C`
  - `dominant_archetype_shifted`
  - `reduced_one_dominant_but_created_new_dominant`
  - `skip_rejoin_exploit_worsened`
- official baseline artifact failure
  - unknown base season config key: `starprice_model_version`

## 5. Focused Test Evidence

The following focused tests were rerun and passed:

- `SeasonConfigExporterTest`
- `SimulationConfigPreflightTest`
- `TuningCandidateGeneratorTest`
- `RuntimeParityCertificationTest`
- `PromotionPatchGeneratorTest`
- `SimulationRejectionAttributionTest`
- `SweepComparatorCampaignRunnerTest`
- `SweepComparatorProfileCatalogTest`
- `CandidatePromotionPipelineTest`

These support the conclusion that several code-level fixes are real, even though the end-to-end qualification path still has critical gaps.

## 6. Reopened Blockers

1. `QB-EXPORT-PREFLIGHT-ARTIFACT`
   - The reproducible checked-in official baseline artifact still contains deprecated key `starprice_model_version`.
   - Result: official exported-baseline qualification fails before simulation.

2. `QB-PROMOTION-COMPARATOR-MISMATCH`
   - The promotion ladder can mark a candidate patch-ready while the official qualification comparator profile rejects that same candidate.
   - Result: balance-suggestion / promotion workflows are not trustworthy end-to-end.

## 7. Residual Risks

- A live DB-backed exporter run could not be executed in this workspace, so exporter CLI verification is indirect.
- The exporter logic looks fixed, but the checked-in reproducible baseline artifact is stale enough to break qualification.
- Comparator evidence and promotion evidence are currently inconsistent for at least one candidate.

## 8. Final Verdict

Final verdict: `not ready`

Why:

- The official reproducible baseline artifact still fails official preflight unchanged.
- The official promotion decision path can disagree with the official comparator path on the same candidate.
- Determinism, staged-generation suppression, rejection attribution, parity, and deterministic patch generation all look materially improved.
- Those improvements are not enough to sign off either controlled simulation use or balance-suggestion workflows while the two blockers above remain open.
