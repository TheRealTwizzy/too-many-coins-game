# Rejected Iteration Audit

Generated: 2026-04-15T18:24:05+00:00
Audited reject events: 8

## Event Classification

- `v3-fast-tuning-aggressive-v3` (verification-v3-fast): local_weakness, cross_system_regression, full_economy_regression, over_broad_change, insufficient_signal, search_inefficiency | wins=6 losses=9 | flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduced_one_dominant_but_created_new_dominant, reduces_lock_in_but_expiry_dominance_rises
- `v3-fast-tuning-balanced-v3` (verification-v3-fast): local_weakness, cross_system_regression, full_economy_regression, over_broad_change, insufficient_signal, search_inefficiency | wins=6 losses=9 | flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduces_lock_in_but_expiry_dominance_rises, skip_rejoin_exploit_worsened
- `v3-fast-tuning-conservative-v3` (verification-v3-fast): cross_system_regression, full_economy_regression, bad_metric_targeting, over_broad_change, insufficient_signal, search_inefficiency | wins=10 losses=5 | flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduced_one_dominant_but_created_new_dominant, reduces_lock_in_but_expiry_dominance_rises, skip_rejoin_exploit_worsened
- `v2-aggressive-tuning-verify-v2-1` (verification-v2): cross_system_regression, full_economy_regression, bad_metric_targeting, over_broad_change, insufficient_signal, search_inefficiency | wins=10 losses=5 | flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduced_one_dominant_but_created_new_dominant, reduces_lock_in_but_expiry_dominance_rises
- `v2-aggressive-tuning-verify-v2-2` (verification-v2): cross_system_regression, full_economy_regression, bad_metric_targeting, insufficient_signal, search_inefficiency | wins=8 losses=7 | flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduces_lock_in_but_expiry_dominance_rises
- `v2-balanced-tuning-verify-v2-1` (verification-v2): cross_system_regression, full_economy_regression, bad_metric_targeting, insufficient_signal, search_inefficiency | wins=8 losses=6 | flags=lock_in_down_but_expiry_dominance_up, reduces_lock_in_but_expiry_dominance_rises
- `v2-balanced-tuning-verify-v2-2` (verification-v2): cross_system_regression, full_economy_regression, bad_metric_targeting, over_broad_change, insufficient_signal, search_inefficiency | wins=9 losses=6 | flags=candidate_improves_B_but_worsens_C, dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduces_lock_in_but_expiry_dominance_rises
- `v2-balanced-tuning-verify-v2-3` (verification-v2): local_weakness, cross_system_regression, full_economy_regression, over_broad_change, insufficient_signal, search_inefficiency | wins=7 losses=8 | flags=candidate_improves_B_but_worsens_C, lock_in_down_but_expiry_dominance_up, long_run_concentration_worsened, reduces_lock_in_but_expiry_dominance_rises, skip_rejoin_exploit_worsened

## Dominant Failure Patterns

- `lock_in_down_but_expiry_dominance_up`: 8
- `reduces_lock_in_but_expiry_dominance_rises`: 8
- `dominant_archetype_shifted`: 6
- `reduced_one_dominant_but_created_new_dominant`: 3
- `skip_rejoin_exploit_worsened`: 3
- `candidate_improves_B_but_worsens_C`: 2
- `long_run_concentration_worsened`: 1