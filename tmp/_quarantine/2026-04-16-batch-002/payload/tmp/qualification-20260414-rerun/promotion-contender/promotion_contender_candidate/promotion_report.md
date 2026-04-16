# Candidate Promotion Report

- Candidate: `promotion_contender_candidate`
- Seed: `qualification-20260414-rerun-contender`
- Pipeline status: `ineligible`
- Patch ready: `false`
- Promotion eligible: `false`
- Debug bypass used: `false`

## Stage Outcomes

### 1. candidate schema validation

- Status: `pass`
- Summary: Candidate schema validated successfully.
- Artifacts:
  - `schema_validation_json` => `tmp\qualification-20260414-rerun\promotion-contender\promotion_contender_candidate\01_candidate_schema_validation\candidate_schema_validation.json`

### 2. effective-config preflight

- Status: `fail`
- Summary: Simulation preflight failed due to inactive candidate changes: season.vault_config (inactive_unreferenced). See audit: tmp\qualification-20260414-rerun\promotion-contender\promotion_contender_candidate\02_effective_config_preflight\effective_config_audit.md

### 3. targeted subsystem harnesses

- Status: `blocked`
- Summary: blocked by failed prior required stage

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

