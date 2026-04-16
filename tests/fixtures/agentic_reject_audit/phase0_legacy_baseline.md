# Phase 0 Legacy Baseline

- Contract: legacy-auditor-direct (`AgenticRejectedIterationAuditor::run`)
- Heavy optimizer path used: NO
- Captured UTC: 2026-04-16T19:47:57.2159304Z
- Commit: `c7f9747da288`
- PHP: `PHP 8.5.4 (cli) (built: Mar 10 2026 23:30:42) (ZTS Visual C++ 2022 x64)`

## Core Fields

- `audited_events_count`: 8
- `audited_events` length: 8
- `key_failure_patterns`: lock_in_down_but_expiry_dominance_up, reduces_lock_in_but_expiry_dominance_rises, dominant_archetype_shifted, reduced_one_dominant_but_created_new_dominant, skip_rejoin_exploit_worsened, candidate_improves_B_but_worsens_C

## Derived Projection

- `rejected_iteration_audit.audited_events_count`
- `rejected_iteration_audit.key_failure_patterns`
- `rejected_iteration_audit.flag_histogram`