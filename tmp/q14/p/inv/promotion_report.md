# Candidate Promotion Report

- Candidate: `inv`
- Seed: `q14-inv`
- Pipeline status: `ineligible`
- Patch ready: `false`
- Promotion eligible: `false`
- Debug bypass used: `false`

## Stage Outcomes

### 1. candidate schema validation

- Status: `fail`
- Summary: Economic candidate validation failed: season.hoarding_safe_hours (candidate_disabled_subsystem).
- Artifacts:
  - `schema_validation_json` => `tmp/q14/p\inv\01_candidate_schema_validation\candidate_schema_validation.json`

### 2. effective-config preflight

- Status: `blocked`
- Summary: blocked by failed prior required stage

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

