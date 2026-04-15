# Candidate Promotion Report

- Candidate: `late_stage_failure_candidate`
- Seed: `qualification-20260414-rerun-late`
- Pipeline status: `eligible`
- Patch ready: `true`
- Promotion eligible: `true`
- Debug bypass used: `false`

## Stage Outcomes

### 1. candidate schema validation

- Status: `pass`
- Summary: Candidate schema validated successfully.
- Artifacts:
  - `schema_validation_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\01_candidate_schema_validation\candidate_schema_validation.json`

### 2. effective-config preflight

- Status: `pass`
- Summary: Effective-config preflight passed and produced canonical audit artifacts.
- Artifacts:
  - `effective_config_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\02_effective_config_preflight\effective_config.json`
  - `effective_config_audit_md` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\02_effective_config_preflight\effective_config_audit.md`

### 3. targeted subsystem harnesses

- Status: `pass`
- Summary: Targeted subsystem harnesses passed.
- Artifacts:
  - `targeted_harness_report_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\03_targeted_subsystem_harnesses\targeted_harness_report.json`

### 4. full single-season validation

- Status: `pass`
- Summary: Full single-season validation completed with config audit status `pass`.
- Artifacts:
  - `single_season_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\04_full_single_season_validation\promotion_single_season_late_stage_failure_candidate.json`
  - `single_season_csv` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\04_full_single_season_validation\promotion_single_season_late_stage_failure_candidate.csv`
  - `effective_config_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\04_full_single_season_validation\audit\effective_config.json`
  - `effective_config_audit_md` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\04_full_single_season_validation\audit\effective_config_audit.md`

### 5. multi-season exploit/regression validation

- Status: `pass`
- Summary: Multi-season exploit/regression validation passed without regression flags.
- Artifacts:
  - `multi_season_validation_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\05_multi_season_exploit_regression_validation\multi_season_validation.json`
  - `baseline_lifetime_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\05_multi_season_exploit_regression_validation\promotion_lifetime_baseline.json`
  - `baseline_lifetime_csv` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\05_multi_season_exploit_regression_validation\promotion_lifetime_baseline.csv`
  - `candidate_lifetime_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\05_multi_season_exploit_regression_validation\promotion_lifetime_candidate.json`
  - `candidate_lifetime_csv` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\05_multi_season_exploit_regression_validation\promotion_lifetime_candidate.csv`

### 6. patch serialization validation

- Status: `pass`
- Summary: Patch serialization validation passed.
- Artifacts:
  - `serialized_patch_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\06_patch_serialization_validation\candidate_patch.json`

### 7. play-test runtime parity certification

- Status: `pass`
- Summary: Play-test runtime parity certification passed for every promotion-critical mechanic domain.
- Artifacts:
  - `runtime_parity_certification_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\07_play_test_runtime_parity_certification\runtime_parity_certification.json`
  - `runtime_parity_certification_md` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\07_play_test_runtime_parity_certification\runtime_parity_certification.md`

### 8. play-test repo compatibility validation

- Status: `pass`
- Summary: Play-test repo compatibility validation passed.
- Artifacts:
  - `candidate_effective_season_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\08_play_test_repo_compatibility_validation\candidate_effective_season.json`
  - `sweep_manifest_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\08_play_test_repo_compatibility_validation\sweep\policy_sweep_qualification-20260414-rerun-late_promotion_compat_ppa2_s4.json`
  - `play_test_repo_compatibility_json` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\08_play_test_repo_compatibility_validation\play_test_repo_compatibility.json`
  - `play_test_repo_compatibility_md` => `tmp\qualification-20260414-rerun\promotion-late\late_stage_failure_candidate\08_play_test_repo_compatibility_validation\play_test_repo_compatibility.md`

### 9. promotion eligibility marking

- Status: `pass`
- Summary: Candidate passed every required promotion stage and is patch-ready.

