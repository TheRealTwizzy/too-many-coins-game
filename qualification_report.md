# Qualification Report

## A. Baseline refs and commits used

- Qualification date: `2026-04-14`
- Repo HEAD: `162a74d94d94eda426b6f723a141cd4a1b8e629f`
- Fixed baseline snapshot: `simulation_output/current-db/export/current_season.json`
- Baseline SHA-256: `EE81E680935B55E16721ED53B924086249C0233F8716094C4E19DAACECFD9A07`
- Provenance cross-check: `simulation_output/current-db/agentic-optimization/agentic-current-db-v4/baseline_config_snapshot.json`

## B. Scenario bundle and seed bundle

- Promotion seed prefix: `qualification-20260414`
- Promotion ladder size: `players_per_archetype=2`, `season_count=4`
- Standalone sweep bundle source: `simulation_output/sweep/followup-tuning-candidates-20260413.json`
- Fixed sweep scenarios:
  - `phase-gated-safe-24h-v1`
  - `phase-gated-safe-48h-v1`
  - `phase-gated-high-floor-v1`
  - `phase-gated-plus-inflation-tighten-v1`
- Diagnostic sanitized baseline: `tmp/q14/baseline_promo.json`
  - This removed only `created_at` from the same fixed baseline snapshot so downstream ladder behavior could be observed after the export/preflight blocker.

## C. Candidate list and expected outcomes

- `invalid_disabled_subsystem`
  - Source: handcrafted `hoarding_safe_hours` patch
  - Expected: fail validation/preflight
- `coupled_overbundled_candidate`
  - Source: staged v3 stage-4 mixed bundle with required sink activation
  - Expected: fail early
- `single_knob_candidate`
  - Source: staged v3 stage-1 `base_ubi_active_per_tick=32`
  - Expected: valid single-knob candidate
- `late_stage_failure_candidate`
  - Source: `phase-gated-safe-24h-v1`
  - Expected: pass early, fail later
- `promotion_contender_candidate`
  - Source: strongest current staged full-confirmation hoarding candidate with required sink activation
  - Expected: best available contender for promotion eligibility

## D. Actual outcomes by stage

- Official baseline run using `simulation_output/current-db/export/current_season.json`
  - `invalid_disabled_subsystem`
    - Stage 1 `candidate_schema_validation`: `fail`
    - Stages 2-8: `blocked`
  - `single_knob_candidate`
    - Stage 1: `pass`
    - Stage 2 `effective_config_preflight`: `fail`
    - Stages 3-8: `blocked`
  - `late_stage_failure_candidate`
    - Stage 1: `pass`
    - Stage 2: `fail`
    - Stages 3-8: `blocked`
  - `coupled_overbundled_candidate`
    - Stage 1: `pass`
    - Stage 2: `fail`
    - Stages 3-8: `blocked`
  - `promotion_contender_candidate`
    - Stage 1: `pass`
    - Stage 2: `fail`
    - Stages 3-8: `blocked`

- Diagnostic continuation on sanitized baseline `tmp/q14/baseline_promo.json`
  - `single_knob_candidate` as `s1s`
    - Stage 1: `pass`
    - Stage 2: `pass`
    - Stage 3 `targeted_subsystem_harnesses`: `fail`
    - Stages 4-8: `blocked`
  - `late_stage_failure_candidate` as `lates`
    - Stages 1-3 began and artifacts were created
    - Final `promotion_state.json` was not written before timeout

## E. Failures by reason code

- `candidate_disabled_subsystem`
  - Observed in strict lint for `invalid_disabled_subsystem`
  - Also exposed a generator/baseline mismatch: fresh staged candidate generation emitted hoarding-subsystem candidates that are inactive against the fixed baseline because `hoarding_sink_enabled=0`

- `Unknown base season config key: created_at`
  - Observed in promotion ladder stage 2 for every valid candidate when using the official baseline export
  - This is not just a candidate failure. It is a broken handoff between canonical export and canonical preflight.

- Targeted harness blocking metrics on diagnostic continuation
  - `boost_underperformance` failed on metric `boost_focused_gap`
  - `skip_rejoin_exploit_worsened` failed on metric `skip_strategy_edge`

## F. Rejection attribution quality assessment

- Direct standalone sweep/comparator execution did not complete within the qualification timeout budget after the export blocker was bypassed, so full CLI attribution evidence is incomplete.
- Focused tests still provide meaningful evidence:
  - `Simulation Rejection Attribution` test passed
  - It verified both single-knob attribution reporting and explicit interaction ambiguity for bundled failures
- Assessment:
  - Quality appears structurally good in isolated tests
  - End-to-end operational qualification remains incomplete because the real sweep/comparator path did not finish inside the campaign window

## G. Subsystem harness screening effectiveness

- Strong evidence from the sanitized single-knob run:
  - Selected families: `boost_underperformance`, `skip_rejoin_exploit_worsened`, `star_affordability_pricing_instability`
  - Failed families:
    - `boost_underperformance` on `boost_focused_gap`
    - `skip_rejoin_exploit_worsened` on `skip_strategy_edge`
  - Passed family:
    - `star_affordability_pricing_instability`
- Assessment:
  - The targeted harness gate is doing real filtering and is not a formality
  - It blocked a seemingly simple single-knob candidate before costlier downstream stages
  - That is good screening behavior

## H. Promotion ladder enforcement assessment

- Enforcement is strict and correctly blocking non-passing candidates.
- Stage ordering and blocking behavior look correct:
  - invalid schema failure blocks later stages
  - preflight failure blocks later stages
  - targeted harness failure blocks later stages
- The ladder is not usable with the official canonical export right now because stage 2 rejects the export format itself.
- Assessment:
  - Enforcement logic is good
  - Promotion readiness is bad because the official entrypoints do not compose cleanly

## I. Parity certification summary

- Standalone CLI parity run succeeded:
  - Command: `php scripts/certify_runtime_parity.php --candidate-id=q14-parity --seed=q14-parity --season-config=tmp/q14/baseline_promo.json --output=tmp/q14/par`
  - Result: `pass`
  - Artifacts:
    - `tmp/q14/par/runtime_parity_certification.json`
    - `tmp/q14/par/runtime_parity_certification.md`
- Required domains covered:
  - `hoarding_sink_behavior`
  - `boost_behavior`
  - `lock_in_timing`
  - `expiry_timing`
  - `star_pricing_affordability`
  - `rejoin_participation_effects`
  - `blackout_finalization_interactions`
- Material drift count: `0`

## J. Deterministic patch generation summary

- No real qualification candidate became promotion-eligible, so this check is diagnostic rather than promotion-ready.
- Diagnostic result:
  - Same canonical config + different `bundle_id`:
    - `repo_patch.diff` hash stayed identical
    - `promotion_bundle.json` hash changed
    - Interpretation: diff payload is stable, metadata is bundle-id-dependent
  - Same canonical config + same `bundle_id` in two output roots:
    - `promotion_bundle.json` hash matched
    - `repo_patch.diff` hash matched
    - staged SQL file hash matched
- Assessment:
  - Patch generation is deterministic for identical semantic inputs when the bundle identity is held constant
  - Bundle metadata is intentionally identity-sensitive

## K. Residual risks

- Reopened blocker: official canonical export is incompatible with official preflight
- Reopened blocker: staged candidate generation is not baseline-aware enough to avoid inactive subsystem knobs
- Reopened blocker: Simulation D payload determinism is still broken because artifact paths leak into compared payloads
- Operational concern: full standalone sweep/comparator and harness runs are expensive enough in this environment to exceed the qualification timeout budget
- Qualification gap: no real candidate reached stages 4-8 under the official baseline, so later-stage artifact generation was only covered by focused tests, not by the primary campaign

## L. Final verdict

`not ready`

### Why

- The official pipeline cannot even consume the official canonical export without failing stage 2 preflight on `created_at`.
- A fresh staged candidate bundle produced invalid hoarding-subsystem candidates against the fixed baseline.
- Simulation D still fails the focused determinism qualification because payloads differ across same-seed reruns.
- Although parity certification and patch generation components behave well in isolation, the end-to-end promotion path is not currently reliable enough for controlled production use.

### Reopened blockers

1. `QB-EXPORT-PREFLIGHT-MISMATCH`
   - `tools/export-season-config.php` exports `SELECT *` season rows, including `created_at`
   - `SimulationConfigPreflight::normalizeBaseSeasonValues()` rejects non-`SEASON_ECONOMY_COLUMNS` keys
   - Result: canonical export cannot feed canonical promotion preflight

2. `QB-STAGED-CANDIDATE-INACTIVE-SURFACE`
   - fresh staged generation emitted sink-tuning candidates while the fixed baseline had `hoarding_sink_enabled=0`
   - Result: generated candidates fail lint/preflight before any real simulation signal is available

3. `QB-SIM-D-PAYLOAD-DETERMINISM`
   - focused PHPUnit qualification still fails Simulation D payload equality for same-seed reruns because artifact paths differ between runs
   - Result: repeated-run consistency is not yet clean enough for full qualification sign-off
