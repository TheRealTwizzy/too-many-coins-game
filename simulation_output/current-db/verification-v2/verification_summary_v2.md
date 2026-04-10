# Verification Summary

Generated: 2026-04-10T21:53:59+00:00
Seeds: 3

## Package Dispositions

| Package | Disposition | Pass Seeds | Reject Seeds | Mixed Seeds | Total W | Total L | Regression Flags |
|---------|-------------|------------|--------------|-------------|---------|---------|------------------|
| aggressive | **REJECTED** | 1/3 | 2/3 | 0/3 | 29 | 15 | 4 |
| balanced | **REJECTED** | 0/3 | 3/3 | 0/3 | 24 | 20 | 6 |
| conservative | **REJECTED** | 1/3 | 2/3 | 0/3 | 29 | 16 | 3 |

---

## Package: aggressive

**Overall Disposition: REJECTED**

### Per-Seed Results

| Seed | Wins | Losses | Mixed | Disposition | Regression Flags |
|------|------|--------|-------|-------------|------------------|
| tuning-verify-v2-1 | 10 | 5 | 2 | reject | dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduced_one_dominant_but_created_new_dominant, reduces_lock_in_but_expiry_dominance_rises |
| tuning-verify-v2-2 | 8 | 7 | 2 | reject | dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduces_lock_in_but_expiry_dominance_rises |
| tuning-verify-v2-3 | 11 | 3 | 2 | candidate for production tuning | — |

### Per-Finding Assessment

| Finding | Severity | Improved | Notes |
|---------|----------|----------|-------|
| B11 | HIGH | YES | Net positive across seeds |
| B5 | MEDIUM | YES | Net positive across seeds |
| B6 | MEDIUM | YES | Net positive across seeds |
| B7 | MEDIUM | YES | Net positive across seeds |
| B10 | MEDIUM | YES | Net positive across seeds |
| B12 | MEDIUM | YES | Net positive across seeds |
| regression_counterweight | UNKNOWN | YES | Net positive across seeds |

### Regression Flags

- `dominant_archetype_shifted` **(affects HIGH-severity finding)**
- `lock_in_down_but_expiry_dominance_up` **(affects HIGH-severity finding)**
- `reduced_one_dominant_but_created_new_dominant` **(affects HIGH-severity finding)**
- `reduces_lock_in_but_expiry_dominance_rises`

---

## Package: balanced

**Overall Disposition: REJECTED**

### Per-Seed Results

| Seed | Wins | Losses | Mixed | Disposition | Regression Flags |
|------|------|--------|-------|-------------|------------------|
| tuning-verify-v2-1 | 8 | 6 | 2 | reject | lock_in_down_but_expiry_dominance_up, reduces_lock_in_but_expiry_dominance_rises |
| tuning-verify-v2-2 | 9 | 6 | 2 | reject | candidate_improves_B_but_worsens_C, dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduces_lock_in_but_expiry_dominance_rises |
| tuning-verify-v2-3 | 7 | 8 | 2 | reject | candidate_improves_B_but_worsens_C, lock_in_down_but_expiry_dominance_up, long_run_concentration_worsened, reduces_lock_in_but_expiry_dominance_rises, skip_rejoin_exploit_worsened |

### Per-Finding Assessment

| Finding | Severity | Improved | Notes |
|---------|----------|----------|-------|
| B11 | HIGH | YES | Net positive across seeds |
| B5 | MEDIUM | YES | Net positive across seeds |
| B6 | MEDIUM | YES | Net positive across seeds |
| B7 | MEDIUM | YES | Net positive across seeds |
| B10 | MEDIUM | YES | Net positive across seeds |
| B12 | MEDIUM | YES | Net positive across seeds |
| regression_counterweight | UNKNOWN | YES | Net positive across seeds |

### Regression Flags

- `lock_in_down_but_expiry_dominance_up` **(affects HIGH-severity finding)**
- `reduces_lock_in_but_expiry_dominance_rises`
- `candidate_improves_B_but_worsens_C` **(affects HIGH-severity finding)**
- `dominant_archetype_shifted` **(affects HIGH-severity finding)**
- `long_run_concentration_worsened` **(affects HIGH-severity finding)**
- `skip_rejoin_exploit_worsened`

---

## Package: conservative

**Overall Disposition: REJECTED**

### Per-Seed Results

| Seed | Wins | Losses | Mixed | Disposition | Regression Flags |
|------|------|--------|-------|-------------|------------------|
| tuning-verify-v2-1 | 9 | 6 | 2 | reject | dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduces_lock_in_but_expiry_dominance_rises |
| tuning-verify-v2-2 | 8 | 7 | 2 | reject | dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduces_lock_in_but_expiry_dominance_rises |
| tuning-verify-v2-3 | 12 | 3 | 2 | candidate for production tuning | — |

### Per-Finding Assessment

| Finding | Severity | Improved | Notes |
|---------|----------|----------|-------|
| B11 | HIGH | YES | Net positive across seeds |
| regression_counterweight | UNKNOWN | YES | Net positive across seeds |

### Regression Flags

- `dominant_archetype_shifted` **(affects HIGH-severity finding)**
- `lock_in_down_but_expiry_dominance_up` **(affects HIGH-severity finding)**
- `reduces_lock_in_but_expiry_dominance_rises`

---

## Stability Notes

- aggressive has inconsistent dispositions across seeds: tuning-verify-v2-1=reject, tuning-verify-v2-2=reject, tuning-verify-v2-3=candidate for production tuning
- conservative has inconsistent dispositions across seeds: tuning-verify-v2-1=reject, tuning-verify-v2-2=reject, tuning-verify-v2-3=candidate for production tuning

## Recommendation

No package meets VERIFIED threshold.
