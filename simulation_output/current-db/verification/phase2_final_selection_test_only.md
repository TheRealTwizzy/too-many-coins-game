# Phase 2 Final Selection (Test-Only)

Selected package: **conservative-v2**

Label: **Recommended for test-only play-testing under controlled review**

## Why this package won

- From `verification_summary_v2`, conservative-v2 has the lowest regression-flag count among v2 candidates (3 vs 4 aggressive, 6 balanced).
- It preserves LOW-risk changes only and avoids larger multi-axis volatility from balanced/aggressive.
- It still addresses the highest-severity hoarding advantage finding (B11) with net-positive wins.

## Remaining regressions

- `dominant_archetype_shifted`
- `lock_in_down_but_expiry_dominance_up`
- `reduces_lock_in_but_expiry_dominance_rises`

These are known and bounded for test-only validation, with conservative magnitude changes chosen to limit blast radius.

## Runtime note

A v3 generation was produced, but full sweep verification in this workstation session was blocked by tunnel/auth runtime constraints and long-running sweeps that did not complete within the bounded execution window. Promotion proceeds with the strongest already-verified test-safe candidate.
