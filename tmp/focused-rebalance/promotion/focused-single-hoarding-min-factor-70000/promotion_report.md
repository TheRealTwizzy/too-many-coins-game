# Candidate Promotion Report

- Candidate: `focused-single-hoarding-min-factor-70000`
- Seed: `focused-single-hoarding-min-factor-70000`
- Pipeline status: `ineligible`
- Patch ready: `false`
- Promotion eligible: `false`
- Debug bypass used: `false`

## Stage Outcomes

### 1. candidate schema validation

- Status: `pass`
- Summary: Candidate schema validated successfully.
- Artifacts:
  - `schema_validation_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\01_candidate_schema_validation\candidate_schema_validation.json`

### 2. effective-config preflight

- Status: `pass`
- Summary: Effective-config preflight passed and produced canonical audit artifacts.
- Artifacts:
  - `effective_config_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\02_effective_config_preflight\effective_config.json`
  - `effective_config_audit_md` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\02_effective_config_preflight\effective_config_audit.md`

### 3. targeted subsystem harnesses

- Status: `pass`
- Summary: Targeted subsystem harnesses passed.
- Artifacts:
  - `targeted_harness_report_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\03_targeted_subsystem_harnesses\targeted_harness_report.json`

### 4. full single-season validation

- Status: `pass`
- Summary: Full single-season validation completed with config audit status `pass`.
- Artifacts:
  - `single_season_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\04_full_single_season_validation\promotion_single_season_focused-single-hoarding-min-factor-70000.json`
  - `single_season_csv` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\04_full_single_season_validation\promotion_single_season_focused-single-hoarding-min-factor-70000.csv`
  - `effective_config_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\04_full_single_season_validation\audit\effective_config.json`
  - `effective_config_audit_md` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\04_full_single_season_validation\audit\effective_config_audit.md`

### 5. multi-season exploit/regression validation

- Status: `pass`
- Summary: Multi-season exploit/regression validation passed without regression flags.
- Artifacts:
  - `multi_season_validation_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\05_multi_season_exploit_regression_validation\multi_season_validation.json`
  - `baseline_lifetime_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\05_multi_season_exploit_regression_validation\promotion_lifetime_baseline.json`
  - `baseline_lifetime_csv` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\05_multi_season_exploit_regression_validation\promotion_lifetime_baseline.csv`
  - `candidate_lifetime_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\05_multi_season_exploit_regression_validation\promotion_lifetime_candidate.json`
  - `candidate_lifetime_csv` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\05_multi_season_exploit_regression_validation\promotion_lifetime_candidate.csv`

### 6. official qualification comparator validation

- Status: `fail`
- Summary: Official qualification comparator rejected the candidate for promotion readiness (`reject`).
- Artifacts:
  - `qualification_base_season_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\06_official_qualification_comparator_validation\qualification_base_season.json`
  - `qualification_candidate_bundle_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\06_official_qualification_comparator_validation\qualification_candidate_bundle.json`
  - `qualification_campaign_report_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\06_official_qualification_comparator_validation\campaign\sweep_comparator_report.json`
  - `qualification_campaign_report_md` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\06_official_qualification_comparator_validation\campaign\sweep_comparator_report.md`
  - `qualification_comparison_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\06_official_qualification_comparator_validation\campaign\comparator\comparison_focused-single-hoarding-min-factor-70000.json`
  - `qualification_sweep_manifest_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\06_official_qualification_comparator_validation\campaign\sweep\policy_sweep_focused-single-hoarding-min-factor-70000_ppa2_s4.json`
  - `qualification_rejection_attribution_json` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\06_official_qualification_comparator_validation\campaign\comparator\rejections\focused-single-hoarding-min-factor-70000\focused-single-hoarding-min-factor-70000\rejection_attribution.json`
  - `qualification_rejection_attribution_md` => `tmp/focused-rebalance/promotion\focused-single-hoarding-min-factor-70000\06_official_qualification_comparator_validation\campaign\comparator\rejections\focused-single-hoarding-min-factor-70000\focused-single-hoarding-min-factor-70000\rejection_attribution.md`

### 7. patch serialization validation

- Status: `blocked`
- Summary: blocked by failed prior required stage

### 8. play-test runtime parity certification

- Status: `blocked`
- Summary: blocked by failed prior required stage

### 9. play-test repo compatibility validation

- Status: `blocked`
- Summary: blocked by failed prior required stage

### 10. promotion eligibility marking

- Status: `fail`
- Summary: Candidate is not patch-ready because one or more required promotion stages did not pass.

