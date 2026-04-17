# Gate G0 Final Pass/Fail Criteria

## PASS (all required)
1. Seven consecutive calendar days of evidence exist under one window.
2. Each day no-flag mode resolves to `legacy`.
3. Each day Phase 0 parity passes exactly for:
   - `audited_events_count`
   - ordered `audited_events`
   - `flag_histogram`
   - `key_failure_patterns`
   - derived `rejected_iteration_audit` projection
4. No no-flag run emits:
   - `rejected_iteration_shadow_parity.json`
   - `rejected_iteration_manifest_preferred_diagnostic.json`
   - `rejected_iteration_manifest_strict_diagnostic.json`
5. Protected audit contract remains stable for default/no-flag evidence checks.
6. Commit/runtime/checksum continuity is maintained across the window, or the window is reset and restarted.
7. Evidence completeness is present for each day (metadata, commands, parity result, diagnostics absence, checksums, notes).

## FAIL TRIGGERS (any one)
1. No-flag mode is not `legacy`.
2. Any required Phase 0 parity mismatch.
3. Any non-default diagnostic appears in no-flag run outputs.
4. Default output drift affecting protected rejected-iteration audit contract.
5. Missing/incomplete daily evidence.
6. Commit change, runtime substrate change, or source checksum change without explicit reset/restart.

## RESET RULE
- Any fail trigger invalidates continuity; restart from Day 01 with a new window folder.
