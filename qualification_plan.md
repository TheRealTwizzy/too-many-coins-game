# Qualification Plan

Qualification date: `2026-04-14`

## Objective

Rerun the official qualification path after the reopened blocker fixes and determine whether the current simulation suite is ready for:

- controlled simulation use
- balance-suggestion workflows

## Baseline And Reproducibility Inputs

- Git commit: `4936633dabe17f64e58b672f71b3a9b01fc6772a`
- Qualification workspace: `tmp/qualification-20260414-rerun`
- Canonical baseline snapshot used for all run-time checks:
  - `simulation_output/current-db/export/current_season_economy_only.json`
- Scenario bundle:
  - `simulation_output/sweep/followup-tuning-candidates-20260413.json`
- Seed bundle root:
  - `qualification-20260414-rerun`

Exporter note:

- The canonical exporter entrypoint is `php tools/export-season-config.php --output=FILE --metadata-output=FILE`.
- In this workspace the exporter cannot be executed end-to-end because the configured DB connection is unavailable.
- To keep the run reproducible, qualification uses the checked-in canonical export snapshot above and separately verifies exporter behavior through source inspection and focused tests.

## Canonical Commands / Entrypoints

- Baseline export:
  - `php tools/export-season-config.php --output=FILE --metadata-output=FILE`
- Candidate linting:
  - `php scripts/lint_candidate_packages.php --input=FILE --season-config=simulation_output/current-db/export/current_season_economy_only.json`
- Effective-config preflight:
  - canonical resolver: `scripts/simulation/SimulationConfigPreflight.php`
  - official exercised entrypoints:
    - `php scripts/simulate_economy.php --season-config=FILE ...`
    - `php scripts/promote_simulation_candidate.php --candidate=FILE --season-config=FILE ...`
- Staged candidate generation:
  - `php scripts/generate_tuning_candidates.php --diagnosis=simulation_output/current-db/diagnosis/diagnosis_report.json --season-config=simulation_output/current-db/export/current_season_economy_only.json --output=tmp/qualification-20260414-rerun/staged-candidates --version=3`
- Rejection attribution:
  - `php scripts/compare_simulation_results.php --seed=VALUE --sweep-manifest=FILE --output=DIR`
- Targeted subsystem harnesses:
  - `php scripts/simulate_coupling_harnesses.php --seed=VALUE --season-config=FILE --candidate-patch=FILE --output=DIR`
- Promotion ladder execution:
  - `php scripts/promote_simulation_candidate.php --candidate=FILE --candidate-id=ID --seed=VALUE --season-config=FILE --output=DIR --players-per-archetype=2 --season-count=4`
- Canonical config export:
  - same exporter as baseline export: `php tools/export-season-config.php --output=FILE --metadata-output=FILE`
- Deterministic patch generation:
  - `php scripts/generate_promotion_patch.php --promotion-report=FILE --output=DIR --dry-run`
- Parity certification:
  - `php scripts/certify_runtime_parity.php --candidate-id=ID --seed=VALUE --season-config=FILE --output=DIR`
- Qualification report generation:
  - no dedicated report-generation script is present in the repo
  - qualification reporting will be synthesized into `qualification_results.json` and `qualification_report.md`

## Fixed Candidate Set

Baseline artifact:

- `simulation_output/current-db/export/current_season_economy_only.json`
  - Expected: passes official preflight unchanged and contains only canonical patchable economy keys

Candidate patches:

- `tmp/qualification-20260414-rerun/candidates/invalid_disabled_subsystem.json`
  - `{ "hoarding_safe_hours": 24 }`
  - Expected: fail schema/preflight with `candidate_disabled_subsystem`

- Suppressed family from staged generation:
  - family: `phase_dead_zones`
  - target: `hoarding_window_ticks`
  - Expected: absent from generated packages and present in suppression artifacts with a disabled-baseline reason

- `tmp/qualification-20260414-rerun/candidates/single_knob_candidate.json`
  - generated from fresh stage-1 output
  - Expected: valid single-knob candidate; should clear schema/preflight and provide honest ladder evidence

- `tmp/qualification-20260414-rerun/candidates/late_stage_failure_candidate.json`
  - mirrors official qualification scenario `phase-gated-safe-24h-v1`
  - Expected: pass early validation, then fail later gate(s) or comparator-driven qualification evidence

- `tmp/qualification-20260414-rerun/candidates/promotion_contender_candidate.json`
  - generated from fresh stage-1 output
  - Expected: best available nontrivial contender for promotion-path checks

## Execution Plan

1. Verify the canonical baseline snapshot surface against the contract and run official preflight through `simulate_economy.php` without manual edits.
2. Generate fresh staged candidates against the canonical baseline snapshot and verify disabled-family suppression in the emitted artifacts.
3. Lint the fixed candidate files against the same canonical baseline snapshot.
4. Run the promotion ladder on the representative valid and invalid candidates with `players_per_archetype=2` and `season_count=4`.
5. Run standalone coupling harnesses for the valid candidates to confirm harness artifacts and screening behavior.
6. Run the official sweep/comparator `qualification` profile against the same canonical baseline snapshot.
7. Run determinism checks:
   - same semantic inputs, different output roots
   - intentional semantic drift negative control
   - repeated deterministic patch generation for identical semantic inputs
8. Run standalone parity certification for the best available contender and compare it with promotion-stage parity evidence when present.
9. Synthesize outcomes into `qualification_results.json` and `qualification_report.md`.

## Success / Verdict Rules

- `ready for controlled use`
  - official baseline export/preflight handoff is clean
  - disabled families are suppressed before generation
  - determinism holds for semantic-equivalent runs
  - qualification comparator/rejection attribution completes on the official profile
  - stage gating, parity, and patch generation behave honestly
  - no critical blocker remains for controlled simulation or balance-suggestion workflows

- `ready for simulation only`
  - simulation-facing validation and attribution are trustworthy
  - but promotion/balance-suggestion path still has a material limitation

- `not ready`
  - any critical qualification blocker remains reopened
  - or determinism/comparator/promotion integrity is materially unreliable
