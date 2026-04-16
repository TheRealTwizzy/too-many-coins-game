# Candidate Promotion Report

- Candidate: `ctr`
- Seed: `q14-ctr`
- Pipeline status: `ineligible`
- Patch ready: `false`
- Promotion eligible: `false`
- Debug bypass used: `false`

## Stage Outcomes

### 1. candidate schema validation

- Status: `pass`
- Summary: Candidate schema validated successfully.
- Artifacts:
  - `schema_validation_json` => `tmp/q14/p\ctr\01_candidate_schema_validation\candidate_schema_validation.json`

### 2. effective-config preflight

- Status: `fail`
- Summary: Unknown base season config key: created_at

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

