# Qualification Plan

## Objective

Run a reproducible qualification pass of the current simulation-to-promotion pipeline and determine whether it is ready for controlled production use.

## Fixed Baseline

- Repo HEAD: `162a74d94d94eda426b6f723a141cd4a1b8e629f`
- Baseline season snapshot: `simulation_output/current-db/export/current_season.json`
- Baseline snapshot SHA-256: `EE81E680935B55E16721ED53B924086249C0233F8716094C4E19DAACECFD9A07`
- Baseline provenance cross-check: `simulation_output/current-db/agentic-optimization/agentic-current-db-v4/baseline_config_snapshot.json`

This checked-in export is the fixed economy baseline for the entire qualification pass. No live DB export will be substituted during this run.

## Canonical Pipeline Entry Points

- Candidate validation: `php scripts/lint_candidate_packages.php --input=FILE --season-config=simulation_output/current-db/export/current_season.json`
- Effective-config preflight: canonical resolver in `scripts/simulation/SimulationConfigPreflight.php`, exercised through simulation and promotion entrypoints
- Staged candidate generation: `php scripts/generate_tuning_candidates.php --diagnosis=simulation_output/current-db/diagnosis/diagnosis_report.json --season-config=simulation_output/current-db/export/current_season.json --output=tmp/qualification/staged-candidates --version=3`
- Rejection attribution: `php scripts/compare_simulation_results.php --seed=... --sweep-manifest=FILE --output=...`
- Subsystem harnesses: `php scripts/simulate_coupling_harnesses.php --seed=... --season-config=simulation_output/current-db/export/current_season.json --candidate-patch=FILE`
- Promotion ladder: `php scripts/promote_simulation_candidate.php --candidate=FILE --candidate-id=ID --seed=... --season-config=simulation_output/current-db/export/current_season.json --output=... --players-per-archetype=2 --season-count=4`
- Canonical config export: `php tools/export-season-config.php --output=FILE`
- Deterministic patch generation: `php scripts/generate_promotion_patch.php --promotion-report=FILE --output=... --dry-run`
- Parity certification: `php scripts/certify_runtime_parity.php --candidate-id=ID --seed=... --season-config=FILE --output=...`

## Fixed Validation Bundle

- Qualification workspace root: `tmp/qualification-20260414`
- Promotion seed prefix: `qualification-20260414`
- Promotion ladder validation size: `players_per_archetype=2`, `season_count=4`
- Standalone sweep bundle source: `simulation_output/sweep/followup-tuning-candidates-20260413.json`
- Standalone sweep scenario set:
  - `phase-gated-safe-24h-v1`
  - `phase-gated-safe-48h-v1`
  - `phase-gated-high-floor-v1`
  - `phase-gated-plus-inflation-tighten-v1`
- Standalone sweep validation size: `players_per_archetype=2`, `season_count=4`
- Determinism rerun seed: reuse the same seed for repeated-run comparisons where the code claims deterministic behavior

This bundle is intentionally reduced-cost but representative. It is sized to qualify pipeline behavior, artifact generation, attribution quality, and determinism without changing architecture or adding new tuning logic.

## Qualification Candidate Set

1. `invalid_disabled_subsystem`
   - Source: handcrafted candidate patch
   - Shape: one inactive/shadowed knob on a disabled subsystem
   - Expected outcome: fail validation/preflight with a canonical reason code such as `candidate_disabled_subsystem`

2. `coupled_overbundled_candidate`
   - Source: multi-knob bundle derived from existing tuning artifacts
   - Shape: intentionally coupled, broad candidate touching multiple subsystems
   - Expected outcome: pass schema/preflight, then fail early in targeted harnesses or another early promotion gate

3. `single_knob_candidate`
   - Source: handcrafted single-key patch on the canonical surface
   - Shape: exactly one allowed season knob
   - Expected outcome: pass schema validation and preflight; later-stage outcome to be measured

4. `late_stage_failure_candidate`
   - Source: historical follow-up scenario converted into a candidate patch
   - Shape: plausible focused candidate with prior evidence of later-stage regressions
   - Expected outcome: pass early gates, then fail later-stage validation, parity, or compatibility

5. `promotion_contender_candidate`
   - Source: strongest available historical contender from existing verification artifacts
   - Shape: candidate with the best prior production-tuning signal available in-repo
   - Expected outcome: best chance of reaching promotion eligibility; may still fail under the current ladder

## Execution Order

1. Generate staged candidates from the fixed diagnosis and baseline.
2. Materialize the five qualification candidate JSON files in the qualification workspace.
3. Run candidate lint for each candidate and record validation failures.
4. Run the standalone coupling harness suite on representative non-invalid candidates.
5. Run the full promotion ladder on all five candidates.
6. For at least one rejection candidate, run standalone sweep plus comparator to verify rejection attribution artifacts and reason quality.
7. For any promotion-eligible candidate, run deterministic promotion patch generation twice and compare outputs.
8. Run standalone parity certification on the best available contender and compare with promotion-stage parity results.
9. Run repeated fixed-seed executions to verify determinism claims where applicable.

## Pass/Fail Basis

- Ready for controlled use:
  - canonical entrypoints execute successfully
  - artifacts are produced at each required stage
  - rejection attribution is specific and believable
  - determinism checks pass
  - at least one realistic candidate reaches promotion eligibility and patch generation is deterministic

- Ready for simulation only:
  - simulation, validation, attribution, and harness gates work
  - artifacts and determinism are acceptable
  - but no realistic candidate can currently complete promotion eligibility, or promotion evidence is incomplete

- Not ready:
  - a critical defect blocks qualification itself
  - required artifacts are missing
  - determinism is broken
  - or promotion enforcement/parity behavior is materially unreliable
