# Verification Summary

Generated: 2026-04-10T21:03:01+00:00
Seeds: 3

## Package Dispositions

| Package | Disposition | Pass Seeds | Reject Seeds | Mixed Seeds | Total W | Total L | Regression Flags |
|---------|-------------|------------|--------------|-------------|---------|---------|------------------|
| aggressive | **REJECTED** | 0/3 | 3/3 | 0/3 | 23 | 21 | 7 |
| balanced | **REJECTED** | 0/3 | 3/3 | 0/3 | 15 | 28 | 6 |
| conservative | **REJECTED** | 0/3 | 3/3 | 0/3 | 22 | 24 | 7 |

---

## Package: aggressive

**Overall Disposition: REJECTED**

### Per-Seed Results

| Seed | Wins | Losses | Mixed | Disposition | Regression Flags |
|------|------|--------|-------|-------------|------------------|
| tuning-verify-v1 | 11 | 4 | 2 | reject | dominant_archetype_shifted, skip_rejoin_exploit_worsened |
| tuning-verify-v2 | 5 | 9 | 2 | reject | dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduced_one_dominant_but_created_new_dominant, reduces_lock_in_but_expiry_dominance_rises, skip_rejoin_exploit_worsened |
| tuning-verify-v3 | 7 | 8 | 2 | reject | candidate_improves_B_but_worsens_C, dominant_archetype_shifted, long_run_concentration_worsened, skip_rejoin_exploit_worsened |

### Per-Finding Assessment

| Finding | Severity | Improved | Notes |
|---------|----------|----------|-------|
| B11 | HIGH | YES | Net positive across seeds |
| B5 | MEDIUM | YES | Net positive across seeds |
| B6 | MEDIUM | YES | Net positive across seeds |
| B7 | MEDIUM | YES | Net positive across seeds |
| B10 | MEDIUM | YES | Net positive across seeds |
| B12 | MEDIUM | YES | Net positive across seeds |

### Regression Flags

- `dominant_archetype_shifted` **(affects HIGH-severity finding)**
- `skip_rejoin_exploit_worsened`
- `lock_in_down_but_expiry_dominance_up` **(affects HIGH-severity finding)**
- `reduced_one_dominant_but_created_new_dominant` **(affects HIGH-severity finding)**
- `reduces_lock_in_but_expiry_dominance_rises`
- `candidate_improves_B_but_worsens_C` **(affects HIGH-severity finding)**
- `long_run_concentration_worsened` **(affects HIGH-severity finding)**

---

## Package: balanced

**Overall Disposition: REJECTED**

### Per-Seed Results

| Seed | Wins | Losses | Mixed | Disposition | Regression Flags |
|------|------|--------|-------|-------------|------------------|
| tuning-verify-v1 | 7 | 8 | 2 | reject | dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, reduced_one_dominant_but_created_new_dominant, reduces_lock_in_but_expiry_dominance_rises, skip_rejoin_exploit_worsened |
| tuning-verify-v2 | 5 | 10 | 2 | reject | dominant_archetype_shifted, skip_rejoin_exploit_worsened |
| tuning-verify-v3 | 3 | 10 | 2 | reject | lock_in_down_but_expiry_dominance_up, long_run_concentration_worsened, reduces_lock_in_but_expiry_dominance_rises |

### Per-Finding Assessment

| Finding | Severity | Improved | Notes |
|---------|----------|----------|-------|
| B11 | HIGH | NO | Net negative — did not improve targeted metric |
| B5 | MEDIUM | NO | Net negative — did not improve targeted metric |
| B6 | MEDIUM | NO | Net negative — did not improve targeted metric |
| B7 | MEDIUM | NO | Net negative — did not improve targeted metric |
| B10 | MEDIUM | NO | Net negative — did not improve targeted metric |
| B12 | MEDIUM | NO | Net negative — did not improve targeted metric |

### Regression Flags

- `dominant_archetype_shifted` **(affects HIGH-severity finding)**
- `lock_in_down_but_expiry_dominance_up` **(affects HIGH-severity finding)**
- `reduced_one_dominant_but_created_new_dominant` **(affects HIGH-severity finding)**
- `reduces_lock_in_but_expiry_dominance_rises`
- `skip_rejoin_exploit_worsened`
- `long_run_concentration_worsened` **(affects HIGH-severity finding)**

---

## Package: conservative

**Overall Disposition: REJECTED**

### Per-Seed Results

| Seed | Wins | Losses | Mixed | Disposition | Regression Flags |
|------|------|--------|-------|-------------|------------------|
| tuning-verify-v1 | 9 | 7 | 2 | reject | lock_in_down_but_expiry_dominance_up, reduces_lock_in_but_expiry_dominance_rises, skip_rejoin_exploit_worsened |
| tuning-verify-v2 | 7 | 8 | 2 | reject | dominant_archetype_shifted, seasonal_fairness_improves_but_long_run_concentration_worsens, skip_rejoin_exploit_worsened |
| tuning-verify-v3 | 6 | 9 | 2 | reject | dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, long_run_concentration_worsened, reduced_one_dominant_but_created_new_dominant, reduces_lock_in_but_expiry_dominance_rises, seasonal_fairness_improves_but_long_run_concentration_worsens |

### Per-Finding Assessment

| Finding | Severity | Improved | Notes |
|---------|----------|----------|-------|
| B11 | HIGH | NO | Net negative — did not improve targeted metric |

### Regression Flags

- `lock_in_down_but_expiry_dominance_up` **(affects HIGH-severity finding)**
- `reduces_lock_in_but_expiry_dominance_rises`
- `skip_rejoin_exploit_worsened`
- `dominant_archetype_shifted` **(affects HIGH-severity finding)**
- `seasonal_fairness_improves_but_long_run_concentration_worsens` **(affects HIGH-severity finding)**
- `long_run_concentration_worsened` **(affects HIGH-severity finding)**
- `reduced_one_dominant_but_created_new_dominant` **(affects HIGH-severity finding)**

---

## Cross-Package Comparison

- aggressive shows net improvement (W:23 L:21) while balanced shows net regression (W:15 L:28)
- aggressive shows net improvement (W:23 L:21) while conservative shows net regression (W:22 L:24)

## Recommendation

No package meets VERIFIED threshold.
