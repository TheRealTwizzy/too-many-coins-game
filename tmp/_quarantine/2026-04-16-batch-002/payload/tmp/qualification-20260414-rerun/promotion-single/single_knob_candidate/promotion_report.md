# Candidate Promotion Report

- Candidate: `single_knob_candidate`
- Seed: `qualification-20260414-rerun-single`
- Pipeline status: `ineligible`
- Patch ready: `false`
- Promotion eligible: `false`
- Debug bypass used: `false`

## Stage Outcomes

### 1. candidate schema validation

- Status: `pass`
- Summary: Candidate schema validated successfully.
- Artifacts:
  - `schema_validation_json` => `tmp\qualification-20260414-rerun\promotion-single\single_knob_candidate\01_candidate_schema_validation\candidate_schema_validation.json`

### 2. effective-config preflight

- Status: `pass`
- Summary: Effective-config preflight passed and produced canonical audit artifacts.
- Artifacts:
  - `effective_config_json` => `tmp\qualification-20260414-rerun\promotion-single\single_knob_candidate\02_effective_config_preflight\effective_config.json`
  - `effective_config_audit_md` => `tmp\qualification-20260414-rerun\promotion-single\single_knob_candidate\02_effective_config_preflight\effective_config_audit.md`

### 3. targeted subsystem harnesses

- Status: `fail`
- Summary: Targeted subsystem harnesses detected promotion-blocking regressions.
- Artifacts:
  - `targeted_harness_report_json` => `tmp\qualification-20260414-rerun\promotion-single\single_knob_candidate\03_targeted_subsystem_harnesses\targeted_harness_report.json`

### 4. full single-season validation

- Status: `blocked`
- Summary: blocked by failed prior required stage

### 5. multi-season exploit/regression validation

- Status: `blocked`
- Summary: blocked by failed prior required stage

### 6. patch serialization validation

- Status: `blocked`
- Summary: blocked by failed prior required stage

### 7. play-test runtime parity certification

- Status: `blocked`
- Summary: blocked by failed prior required stage

### 8. play-test repo compatibility validation

- Status: `blocked`
- Summary: blocked by failed prior required stage

### 9. promotion eligibility marking

- Status: `fail`
- Summary: Candidate is not patch-ready because one or more required promotion stages did not pass.

