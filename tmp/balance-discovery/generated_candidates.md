# Economy Tuning Candidates

Generated: 2026-04-15 08:00:12 UTC
Diagnosis source: `tmp/balance-discovery/discovery_diagnosis.json`
Tuning version: v3

## Summary

| Metric | Value |
|---|---|
| Findings processed | 5 |
| Findings tunable | 5 |
| Findings escalated | 0 |
| Candidates generated | 27 |
| Scenarios generated | 27 |
| Suppressed candidate families | 7 |

## Baseline Constraints

| Feature Flag | Baseline Value | Enabled |
|---|---|---|
| `season.hoarding_sink_enabled` | 0 | no |

## Stage Overview

| Stage | Candidates | Blocked from next stage | Suppressed before generation |
|---|---|---|---|
| `stage_1_single_knob` | 6 | 0 | 7 |
| `stage_2_pairwise` | 12 | 0 | 0 |
| `stage_3_constrained_bundle` | 6 | 0 | 0 |
| `stage_4_full_confirmation` | 3 | 3 | 0 |

## Suppressed Families

| Stage | Family | Target | Reason |
|---|---|---|---|
| `stage_1_single_knob` | `boost_roi_imbalance` | `hoarding_min_factor_fp` | Subsystem is disabled in the baseline because `season.hoarding_sink_enabled` resolves t... |
| `stage_1_single_knob` | `hoarding_advantage` | `hoarding_tier2_rate_hourly_fp` | Knob is outside the active search space because `season.hoarding_sink_enabled` resolves... |
| `stage_1_single_knob` | `hoarding_advantage` | `hoarding_tier3_rate_hourly_fp` | Knob is outside the active search space because `season.hoarding_sink_enabled` resolves... |
| `stage_1_single_knob` | `hoarding_advantage` | `hoarding_idle_multiplier_fp` | Knob is outside the active search space because `season.hoarding_sink_enabled` resolves... |
| `stage_1_single_knob` | `lock_in_support` | `starprice_reactivation_window_ticks` | Counterweight dimension has no active primary trigger lane after baseline search-space ... |
| `stage_1_single_knob` | `lock_in_support` | `market_affordability_bias_fp` | Counterweight dimension has no active primary trigger lane after baseline search-space ... |
| `stage_1_single_knob` | `expiry_softening` | `starprice_max_downstep_fp` | Counterweight dimension has no active primary trigger lane after baseline search-space ... |

## stage_1_single_knob

### `stage1-market_affordability_bias_fp-02`

Single-knob learning pass for `market_affordability_bias_fp`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |

### `stage1-starprice_max_downstep_fp-04`

Single-knob learning pass for `starprice_max_downstep_fp`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |

### `stage1-starprice_max_upstep_fp-03`

Single-knob learning pass for `starprice_max_upstep_fp`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |

### `stage1-starprice_reactivation_window_ticks-01`

Single-knob learning pass for `starprice_reactivation_window_ticks`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_reactivation_window_ticks` | 81 | 101 | BD1 |

### `stage1-base_ubi_active_per_tick-06`

Single-knob learning pass for `base_ubi_active_per_tick`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 21
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `base_ubi_active_per_tick` | 30 | 32 | BD4 |

### `stage1-target_spend_rate_per_tick-05`

Single-knob learning pass for `target_spend_rate_per_tick`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 21
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `target_spend_rate_per_tick` | 18 | 17 | BD3 |


## stage_2_pairwise

### `stage2-market_affordability_bias_fp-starprice_max_downstep_fp`

Pairwise validation for `market_affordability_bias_fp` + `starprice_max_downstep_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-market_affordability_bias_fp-02, stage1-starprice_max_downstep_fp-04

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |

### `stage2-market_affordability_bias_fp-starprice_max_upstep_fp`

Pairwise validation for `market_affordability_bias_fp` + `starprice_max_upstep_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-market_affordability_bias_fp-02, stage1-starprice_max_upstep_fp-03

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |

### `stage2-market_affordability_bias_fp-starprice_reactivation_window_ticks`

Pairwise validation for `market_affordability_bias_fp` + `starprice_reactivation_window_ticks`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-market_affordability_bias_fp-02, stage1-starprice_reactivation_window_ticks-01

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `starprice_reactivation_window_ticks` | 81 | 101 | BD1 |

### `stage2-starprice_max_downstep_fp-starprice_max_upstep_fp`

Pairwise validation for `starprice_max_downstep_fp` + `starprice_max_upstep_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-starprice_max_downstep_fp-04, stage1-starprice_max_upstep_fp-03

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |

### `stage2-starprice_max_downstep_fp-starprice_reactivation_window_ticks`

Pairwise validation for `starprice_max_downstep_fp` + `starprice_reactivation_window_ticks`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-starprice_max_downstep_fp-04, stage1-starprice_reactivation_window_ticks-01

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |
| `starprice_reactivation_window_ticks` | 81 | 101 | BD1 |

### `stage2-starprice_max_upstep_fp-starprice_reactivation_window_ticks`

Pairwise validation for `starprice_max_upstep_fp` + `starprice_reactivation_window_ticks`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-starprice_max_upstep_fp-03, stage1-starprice_reactivation_window_ticks-01

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |
| `starprice_reactivation_window_ticks` | 81 | 101 | BD1 |

### `stage2-market_affordability_bias_fp-base_ubi_active_per_tick`

Pairwise validation for `market_affordability_bias_fp` + `base_ubi_active_per_tick`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 26.5
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: stage1-market_affordability_bias_fp-02, stage1-base_ubi_active_per_tick-06

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `base_ubi_active_per_tick` | 30 | 32 | BD4 |

### `stage2-market_affordability_bias_fp-target_spend_rate_per_tick`

Pairwise validation for `market_affordability_bias_fp` + `target_spend_rate_per_tick`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 26.5
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: stage1-market_affordability_bias_fp-02, stage1-target_spend_rate_per_tick-05

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `target_spend_rate_per_tick` | 18 | 17 | BD3 |

### `stage2-starprice_max_downstep_fp-base_ubi_active_per_tick`

Pairwise validation for `starprice_max_downstep_fp` + `base_ubi_active_per_tick`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 26.5
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-starprice_max_downstep_fp-04, stage1-base_ubi_active_per_tick-06

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |
| `base_ubi_active_per_tick` | 30 | 32 | BD4 |

### `stage2-starprice_max_downstep_fp-target_spend_rate_per_tick`

Pairwise validation for `starprice_max_downstep_fp` + `target_spend_rate_per_tick`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 26.5
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-starprice_max_downstep_fp-04, stage1-target_spend_rate_per_tick-05

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |
| `target_spend_rate_per_tick` | 18 | 17 | BD3 |

### `stage2-starprice_max_upstep_fp-base_ubi_active_per_tick`

Pairwise validation for `starprice_max_upstep_fp` + `base_ubi_active_per_tick`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 26.5
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-starprice_max_upstep_fp-03, stage1-base_ubi_active_per_tick-06

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |
| `base_ubi_active_per_tick` | 30 | 32 | BD4 |

### `stage2-starprice_max_upstep_fp-target_spend_rate_per_tick`

Pairwise validation for `starprice_max_upstep_fp` + `target_spend_rate_per_tick`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 26.5
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-starprice_max_upstep_fp-03, stage1-target_spend_rate_per_tick-05

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |
| `target_spend_rate_per_tick` | 18 | 17 | BD3 |


## stage_3_constrained_bundle

### `stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_max_upstep_fp`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage2-market_affordability_bias_fp-starprice_max_downstep_fp, stage2-market_affordability_bias_fp-starprice_max_upstep_fp, stage2-starprice_max_downstep_fp-starprice_max_upstep_fp

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |

### `stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_reactivation_window_ticks`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage2-market_affordability_bias_fp-starprice_max_downstep_fp, stage2-market_affordability_bias_fp-starprice_reactivation_window_ticks, stage2-starprice_max_downstep_fp-starprice_reactivation_window_ticks

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |
| `starprice_reactivation_window_ticks` | 81 | 101 | BD1 |

### `stage3-market_affordability_bias_fp-starprice_max_upstep_fp-starprice_reactivation_window_ticks`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage2-market_affordability_bias_fp-starprice_max_upstep_fp, stage2-market_affordability_bias_fp-starprice_reactivation_window_ticks, stage2-starprice_max_upstep_fp-starprice_reactivation_window_ticks

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |
| `starprice_reactivation_window_ticks` | 81 | 101 | BD1 |

### `stage3-starprice_max_downstep_fp-starprice_max_upstep_fp-starprice_reactivation_window_ticks`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage2-starprice_max_downstep_fp-starprice_max_upstep_fp, stage2-starprice_max_downstep_fp-starprice_reactivation_window_ticks, stage2-starprice_max_upstep_fp-starprice_reactivation_window_ticks

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |
| `starprice_reactivation_window_ticks` | 81 | 101 | BD1 |

### `stage3-base_ubi_active_per_tick-market_affordability_bias_fp-starprice_max_downstep_fp`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 28.33
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage2-market_affordability_bias_fp-base_ubi_active_per_tick, stage2-starprice_max_downstep_fp-base_ubi_active_per_tick, stage2-market_affordability_bias_fp-starprice_max_downstep_fp

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `base_ubi_active_per_tick` | 30 | 32 | BD4 |
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |

### `stage3-base_ubi_active_per_tick-market_affordability_bias_fp-starprice_max_upstep_fp`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 28.33
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage2-market_affordability_bias_fp-base_ubi_active_per_tick, stage2-starprice_max_upstep_fp-base_ubi_active_per_tick, stage2-market_affordability_bias_fp-starprice_max_upstep_fp

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `base_ubi_active_per_tick` | 30 | 32 | BD4 |
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |


## stage_4_full_confirmation

### `stage4-stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_max_upstep_fp`

Full confirmation candidate promoted from constrained bundle `stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_max_upstep_fp`.

- Stage: `stage_4_full_confirmation`
- Knobs: 3
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: no
- Lineage parents: stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_max_upstep_fp
- Advancement notes: final confirmation stage

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |

### `stage4-stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_reactivation_window_ticks`

Full confirmation candidate promoted from constrained bundle `stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_reactivation_window_ticks`.

- Stage: `stage_4_full_confirmation`
- Knobs: 3
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: no
- Lineage parents: stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_reactivation_window_ticks
- Advancement notes: final confirmation stage

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `starprice_max_downstep_fp` | 12960 | 9720 | BD2 |
| `starprice_reactivation_window_ticks` | 81 | 101 | BD1 |

### `stage4-stage3-market_affordability_bias_fp-starprice_max_upstep_fp-starprice_reactivation_window_ticks`

Full confirmation candidate promoted from constrained bundle `stage3-market_affordability_bias_fp-starprice_max_upstep_fp-starprice_reactivation_window_ticks`.

- Stage: `stage_4_full_confirmation`
- Knobs: 3
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: no
- Lineage parents: stage3-market_affordability_bias_fp-starprice_max_upstep_fp-starprice_reactivation_window_ticks
- Advancement notes: final confirmation stage

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 892400 | BD1 |
| `starprice_max_upstep_fp` | 1000 | 750 | BD2 |
| `starprice_reactivation_window_ticks` | 81 | 101 | BD1 |

## Scenarios

### `stage1-market_affordability_bias_fp-02`

- Stage: `stage_1_single_knob`
- Categories: lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "market_affordability_bias_fp": 892400
}
```

### `stage1-starprice_max_downstep_fp-04`

- Stage: `stage_1_single_knob`
- Categories: star_conversion_pricing

```json
{
    "starprice_max_downstep_fp": 9720
}
```

### `stage1-starprice_max_upstep_fp-03`

- Stage: `stage_1_single_knob`
- Categories: star_conversion_pricing

```json
{
    "starprice_max_upstep_fp": 750
}
```

### `stage1-starprice_reactivation_window_ticks-01`

- Stage: `stage_1_single_knob`
- Categories: lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "starprice_reactivation_window_ticks": 101
}
```

### `stage1-base_ubi_active_per_tick-06`

- Stage: `stage_1_single_knob`
- Categories: boost_related

```json
{
    "base_ubi_active_per_tick": 32
}
```

### `stage1-target_spend_rate_per_tick-05`

- Stage: `stage_1_single_knob`
- Categories: boost_related, hoarding_preservation_pressure

```json
{
    "target_spend_rate_per_tick": 17
}
```

### `stage2-market_affordability_bias_fp-starprice_max_downstep_fp`

- Stage: `stage_2_pairwise`
- Categories: lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "market_affordability_bias_fp": 892400,
    "starprice_max_downstep_fp": 9720
}
```

### `stage2-market_affordability_bias_fp-starprice_max_upstep_fp`

- Stage: `stage_2_pairwise`
- Categories: lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "market_affordability_bias_fp": 892400,
    "starprice_max_upstep_fp": 750
}
```

### `stage2-market_affordability_bias_fp-starprice_reactivation_window_ticks`

- Stage: `stage_2_pairwise`
- Categories: lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "market_affordability_bias_fp": 892400,
    "starprice_reactivation_window_ticks": 101
}
```

### `stage2-starprice_max_downstep_fp-starprice_max_upstep_fp`

- Stage: `stage_2_pairwise`
- Categories: star_conversion_pricing

```json
{
    "starprice_max_downstep_fp": 9720,
    "starprice_max_upstep_fp": 750
}
```

### `stage2-starprice_max_downstep_fp-starprice_reactivation_window_ticks`

- Stage: `stage_2_pairwise`
- Categories: star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "starprice_max_downstep_fp": 9720,
    "starprice_reactivation_window_ticks": 101
}
```

### `stage2-starprice_max_upstep_fp-starprice_reactivation_window_ticks`

- Stage: `stage_2_pairwise`
- Categories: star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "starprice_max_upstep_fp": 750,
    "starprice_reactivation_window_ticks": 101
}
```

### `stage2-market_affordability_bias_fp-base_ubi_active_per_tick`

- Stage: `stage_2_pairwise`
- Categories: lock_in_expiry_incentives, star_conversion_pricing, boost_related

```json
{
    "market_affordability_bias_fp": 892400,
    "base_ubi_active_per_tick": 32
}
```

### `stage2-market_affordability_bias_fp-target_spend_rate_per_tick`

- Stage: `stage_2_pairwise`
- Categories: lock_in_expiry_incentives, star_conversion_pricing, boost_related, hoarding_preservation_pressure

```json
{
    "market_affordability_bias_fp": 892400,
    "target_spend_rate_per_tick": 17
}
```

### `stage2-starprice_max_downstep_fp-base_ubi_active_per_tick`

- Stage: `stage_2_pairwise`
- Categories: star_conversion_pricing, boost_related

```json
{
    "starprice_max_downstep_fp": 9720,
    "base_ubi_active_per_tick": 32
}
```

### `stage2-starprice_max_downstep_fp-target_spend_rate_per_tick`

- Stage: `stage_2_pairwise`
- Categories: star_conversion_pricing, boost_related, hoarding_preservation_pressure

```json
{
    "starprice_max_downstep_fp": 9720,
    "target_spend_rate_per_tick": 17
}
```

### `stage2-starprice_max_upstep_fp-base_ubi_active_per_tick`

- Stage: `stage_2_pairwise`
- Categories: star_conversion_pricing, boost_related

```json
{
    "starprice_max_upstep_fp": 750,
    "base_ubi_active_per_tick": 32
}
```

### `stage2-starprice_max_upstep_fp-target_spend_rate_per_tick`

- Stage: `stage_2_pairwise`
- Categories: star_conversion_pricing, boost_related, hoarding_preservation_pressure

```json
{
    "starprice_max_upstep_fp": 750,
    "target_spend_rate_per_tick": 17
}
```

### `stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_max_upstep_fp`

- Stage: `stage_3_constrained_bundle`
- Categories: lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "market_affordability_bias_fp": 892400,
    "starprice_max_downstep_fp": 9720,
    "starprice_max_upstep_fp": 750
}
```

### `stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_reactivation_window_ticks`

- Stage: `stage_3_constrained_bundle`
- Categories: lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "market_affordability_bias_fp": 892400,
    "starprice_max_downstep_fp": 9720,
    "starprice_reactivation_window_ticks": 101
}
```

### `stage3-market_affordability_bias_fp-starprice_max_upstep_fp-starprice_reactivation_window_ticks`

- Stage: `stage_3_constrained_bundle`
- Categories: lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "market_affordability_bias_fp": 892400,
    "starprice_max_upstep_fp": 750,
    "starprice_reactivation_window_ticks": 101
}
```

### `stage3-starprice_max_downstep_fp-starprice_max_upstep_fp-starprice_reactivation_window_ticks`

- Stage: `stage_3_constrained_bundle`
- Categories: star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "starprice_max_downstep_fp": 9720,
    "starprice_max_upstep_fp": 750,
    "starprice_reactivation_window_ticks": 101
}
```

### `stage3-base_ubi_active_per_tick-market_affordability_bias_fp-starprice_max_downstep_fp`

- Stage: `stage_3_constrained_bundle`
- Categories: boost_related, lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "base_ubi_active_per_tick": 32,
    "market_affordability_bias_fp": 892400,
    "starprice_max_downstep_fp": 9720
}
```

### `stage3-base_ubi_active_per_tick-market_affordability_bias_fp-starprice_max_upstep_fp`

- Stage: `stage_3_constrained_bundle`
- Categories: boost_related, lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "base_ubi_active_per_tick": 32,
    "market_affordability_bias_fp": 892400,
    "starprice_max_upstep_fp": 750
}
```

### `stage4-stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_max_upstep_fp`

- Stage: `stage_4_full_confirmation`
- Categories: lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "market_affordability_bias_fp": 892400,
    "starprice_max_downstep_fp": 9720,
    "starprice_max_upstep_fp": 750
}
```

### `stage4-stage3-market_affordability_bias_fp-starprice_max_downstep_fp-starprice_reactivation_window_ticks`

- Stage: `stage_4_full_confirmation`
- Categories: lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "market_affordability_bias_fp": 892400,
    "starprice_max_downstep_fp": 9720,
    "starprice_reactivation_window_ticks": 101
}
```

### `stage4-stage3-market_affordability_bias_fp-starprice_max_upstep_fp-starprice_reactivation_window_ticks`

- Stage: `stage_4_full_confirmation`
- Categories: lock_in_expiry_incentives, star_conversion_pricing

```json
{
    "market_affordability_bias_fp": 892400,
    "starprice_max_upstep_fp": 750,
    "starprice_reactivation_window_ticks": 101
}
```

