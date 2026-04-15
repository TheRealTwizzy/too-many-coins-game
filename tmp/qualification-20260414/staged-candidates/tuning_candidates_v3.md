# Economy Tuning Candidates

Generated: 2026-04-14 16:38:21 UTC
Diagnosis source: `C:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\simulation_output\current-db\diagnosis\diagnosis_report.json`
Tuning version: v3

## Summary

| Metric | Value |
|---|---|
| Findings processed | 12 |
| Findings tunable | 6 |
| Findings escalated | 6 |
| Candidates generated | 32 |
| Scenarios generated | 32 |

## Stage Overview

| Stage | Candidates | Blocked from next stage |
|---|---|---|
| `stage_1_single_knob` | 11 | 1 |
| `stage_2_pairwise` | 12 | 0 |
| `stage_3_constrained_bundle` | 6 | 0 |
| `stage_4_full_confirmation` | 3 | 3 |

## stage_1_single_knob

### `stage1-base_ubi_active_per_tick-04`

Single-knob learning pass for `base_ubi_active_per_tick`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 40
- Risk: LOW
- Eligible for next stage: no
- Lineage parents: none
- Advancement notes: low_confidence

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `base_ubi_active_per_tick` | 30 | 32 | B5,B6 |

### `stage1-hoarding_idle_multiplier_fp-03`

Single-knob learning pass for `hoarding_idle_multiplier_fp`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |

### `stage1-hoarding_tier2_rate_hourly_fp-01`

Single-knob learning pass for `hoarding_tier2_rate_hourly_fp`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |

### `stage1-hoarding_tier3_rate_hourly_fp-02`

Single-knob learning pass for `hoarding_tier3_rate_hourly_fp`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_tier3_rate_hourly_fp` | 1070 | 1177 | B11 |

### `stage1-market_affordability_bias_fp-10`

Single-knob learning pass for `market_affordability_bias_fp`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `market_affordability_bias_fp` | 970000 | 873000 | B11:counterweight |

### `stage1-starprice_max_downstep_fp-11`

Single-knob learning pass for `starprice_max_downstep_fp`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_max_downstep_fp` | 12960 | 15811 | B11:counterweight |

### `stage1-starprice_reactivation_window_ticks-09`

Single-knob learning pass for `starprice_reactivation_window_ticks`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `starprice_reactivation_window_ticks` | 81 | 100 | B11:counterweight |

### `stage1-hoarding_min_factor_fp-07`

Single-knob learning pass for `hoarding_min_factor_fp`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 22
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_min_factor_fp` | 90000 | 93600 | B10 |

### `stage1-hoarding_window_ticks-08`

Single-knob learning pass for `hoarding_window_ticks`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 22
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_window_ticks` | 86400 | 84672 | B12 |

### `stage1-target_spend_rate_per_tick-06`

Single-knob learning pass for `target_spend_rate_per_tick`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 22
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `target_spend_rate_per_tick` | 18 | 17 | B10 |

### `stage1-vault_config-05`

Single-knob learning pass for `vault_config`.

- Stage: `stage_1_single_knob`
- Knobs: 1
- Signal score: 22
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: none

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `vault_config` | [{"tier": 1, "supply": 500,... | [{"tier":1,"supply":575,"co... | B7 |


## stage_2_pairwise

### `stage2-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp`

Pairwise validation for `hoarding_idle_multiplier_fp` + `hoarding_tier2_rate_hourly_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_idle_multiplier_fp-03, stage1-hoarding_tier2_rate_hourly_fp-01

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |

### `stage2-hoarding_idle_multiplier_fp-hoarding_tier3_rate_hourly_fp`

Pairwise validation for `hoarding_idle_multiplier_fp` + `hoarding_tier3_rate_hourly_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_idle_multiplier_fp-03, stage1-hoarding_tier3_rate_hourly_fp-02

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `hoarding_tier3_rate_hourly_fp` | 1070 | 1177 | B11 |

### `stage2-hoarding_idle_multiplier_fp-market_affordability_bias_fp`

Pairwise validation for `hoarding_idle_multiplier_fp` + `market_affordability_bias_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_idle_multiplier_fp-03, stage1-market_affordability_bias_fp-10

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `market_affordability_bias_fp` | 970000 | 873000 | B11:counterweight |

### `stage2-hoarding_idle_multiplier_fp-starprice_max_downstep_fp`

Pairwise validation for `hoarding_idle_multiplier_fp` + `starprice_max_downstep_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_idle_multiplier_fp-03, stage1-starprice_max_downstep_fp-11

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `starprice_max_downstep_fp` | 12960 | 15811 | B11:counterweight |

### `stage2-hoarding_idle_multiplier_fp-starprice_reactivation_window_ticks`

Pairwise validation for `hoarding_idle_multiplier_fp` + `starprice_reactivation_window_ticks`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_idle_multiplier_fp-03, stage1-starprice_reactivation_window_ticks-09

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `starprice_reactivation_window_ticks` | 81 | 100 | B11:counterweight |

### `stage2-hoarding_tier2_rate_hourly_fp-hoarding_tier3_rate_hourly_fp`

Pairwise validation for `hoarding_tier2_rate_hourly_fp` + `hoarding_tier3_rate_hourly_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_tier2_rate_hourly_fp-01, stage1-hoarding_tier3_rate_hourly_fp-02

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |
| `hoarding_tier3_rate_hourly_fp` | 1070 | 1177 | B11 |

### `stage2-hoarding_tier2_rate_hourly_fp-market_affordability_bias_fp`

Pairwise validation for `hoarding_tier2_rate_hourly_fp` + `market_affordability_bias_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_tier2_rate_hourly_fp-01, stage1-market_affordability_bias_fp-10

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |
| `market_affordability_bias_fp` | 970000 | 873000 | B11:counterweight |

### `stage2-hoarding_tier2_rate_hourly_fp-starprice_max_downstep_fp`

Pairwise validation for `hoarding_tier2_rate_hourly_fp` + `starprice_max_downstep_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_tier2_rate_hourly_fp-01, stage1-starprice_max_downstep_fp-11

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |
| `starprice_max_downstep_fp` | 12960 | 15811 | B11:counterweight |

### `stage2-hoarding_tier2_rate_hourly_fp-starprice_reactivation_window_ticks`

Pairwise validation for `hoarding_tier2_rate_hourly_fp` + `starprice_reactivation_window_ticks`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_tier2_rate_hourly_fp-01, stage1-starprice_reactivation_window_ticks-09

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |
| `starprice_reactivation_window_ticks` | 81 | 100 | B11:counterweight |

### `stage2-hoarding_tier3_rate_hourly_fp-market_affordability_bias_fp`

Pairwise validation for `hoarding_tier3_rate_hourly_fp` + `market_affordability_bias_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_tier3_rate_hourly_fp-02, stage1-market_affordability_bias_fp-10

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_tier3_rate_hourly_fp` | 1070 | 1177 | B11 |
| `market_affordability_bias_fp` | 970000 | 873000 | B11:counterweight |

### `stage2-hoarding_tier3_rate_hourly_fp-starprice_max_downstep_fp`

Pairwise validation for `hoarding_tier3_rate_hourly_fp` + `starprice_max_downstep_fp`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_tier3_rate_hourly_fp-02, stage1-starprice_max_downstep_fp-11

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_tier3_rate_hourly_fp` | 1070 | 1177 | B11 |
| `starprice_max_downstep_fp` | 12960 | 15811 | B11:counterweight |

### `stage2-hoarding_tier3_rate_hourly_fp-starprice_reactivation_window_ticks`

Pairwise validation for `hoarding_tier3_rate_hourly_fp` + `starprice_reactivation_window_ticks`.

- Stage: `stage_2_pairwise`
- Knobs: 2
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage1-hoarding_tier3_rate_hourly_fp-02, stage1-starprice_reactivation_window_ticks-09

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_tier3_rate_hourly_fp` | 1070 | 1177 | B11 |
| `starprice_reactivation_window_ticks` | 81 | 100 | B11:counterweight |


## stage_3_constrained_bundle

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-hoarding_tier3_rate_hourly_fp`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: stage2-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp, stage2-hoarding_idle_multiplier_fp-hoarding_tier3_rate_hourly_fp, stage2-hoarding_tier2_rate_hourly_fp-hoarding_tier3_rate_hourly_fp

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |
| `hoarding_tier3_rate_hourly_fp` | 1070 | 1177 | B11 |

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-market_affordability_bias_fp`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: stage2-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp, stage2-hoarding_idle_multiplier_fp-market_affordability_bias_fp, stage2-hoarding_tier2_rate_hourly_fp-market_affordability_bias_fp

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |
| `market_affordability_bias_fp` | 970000 | 873000 | B11:counterweight |

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-starprice_max_downstep_fp`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage2-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp, stage2-hoarding_idle_multiplier_fp-starprice_max_downstep_fp, stage2-hoarding_tier2_rate_hourly_fp-starprice_max_downstep_fp

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |
| `starprice_max_downstep_fp` | 12960 | 15811 | B11:counterweight |

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-starprice_reactivation_window_ticks`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage2-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp, stage2-hoarding_idle_multiplier_fp-starprice_reactivation_window_ticks, stage2-hoarding_tier2_rate_hourly_fp-starprice_reactivation_window_ticks

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |
| `starprice_reactivation_window_ticks` | 81 | 100 | B11:counterweight |

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier3_rate_hourly_fp-market_affordability_bias_fp`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 32
- Risk: LOW
- Eligible for next stage: yes
- Lineage parents: stage2-hoarding_idle_multiplier_fp-hoarding_tier3_rate_hourly_fp, stage2-hoarding_idle_multiplier_fp-market_affordability_bias_fp, stage2-hoarding_tier3_rate_hourly_fp-market_affordability_bias_fp

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `hoarding_tier3_rate_hourly_fp` | 1070 | 1177 | B11 |
| `market_affordability_bias_fp` | 970000 | 873000 | B11:counterweight |

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier3_rate_hourly_fp-starprice_max_downstep_fp`

Constrained 3-knob bundle built only from pairwise-validated knobs.

- Stage: `stage_3_constrained_bundle`
- Knobs: 3
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: yes
- Lineage parents: stage2-hoarding_idle_multiplier_fp-hoarding_tier3_rate_hourly_fp, stage2-hoarding_idle_multiplier_fp-starprice_max_downstep_fp, stage2-hoarding_tier3_rate_hourly_fp-starprice_max_downstep_fp

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `hoarding_tier3_rate_hourly_fp` | 1070 | 1177 | B11 |
| `starprice_max_downstep_fp` | 12960 | 15811 | B11:counterweight |


## stage_4_full_confirmation

### `stage4-stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-hoarding_tier3_rate_hourly_fp`

Full confirmation candidate promoted from constrained bundle `stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-hoarding_tier3_rate_hourly_fp`.

- Stage: `stage_4_full_confirmation`
- Knobs: 3
- Signal score: 32
- Risk: LOW
- Eligible for next stage: no
- Lineage parents: stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-hoarding_tier3_rate_hourly_fp
- Advancement notes: final confirmation stage

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |
| `hoarding_tier3_rate_hourly_fp` | 1070 | 1177 | B11 |

### `stage4-stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-market_affordability_bias_fp`

Full confirmation candidate promoted from constrained bundle `stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-market_affordability_bias_fp`.

- Stage: `stage_4_full_confirmation`
- Knobs: 3
- Signal score: 32
- Risk: LOW
- Eligible for next stage: no
- Lineage parents: stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-market_affordability_bias_fp
- Advancement notes: final confirmation stage

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |
| `market_affordability_bias_fp` | 970000 | 873000 | B11:counterweight |

### `stage4-stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-starprice_max_downstep_fp`

Full confirmation candidate promoted from constrained bundle `stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-starprice_max_downstep_fp`.

- Stage: `stage_4_full_confirmation`
- Knobs: 3
- Signal score: 32
- Risk: MEDIUM
- Eligible for next stage: no
- Lineage parents: stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-starprice_max_downstep_fp
- Advancement notes: final confirmation stage

| Target | Current | Proposed | Finding |
|---|---|---|---|
| `hoarding_idle_multiplier_fp` | 1287500 | 1339000 | B11 |
| `hoarding_tier2_rate_hourly_fp` | 535 | 578 | B11 |
| `starprice_max_downstep_fp` | 12960 | 15811 | B11:counterweight |

## Escalations

| Finding | Category | Severity | Subsystem | Reason |
|---|---|---|---|---|
| B8 | non_viable_archetype | HIGH | economy_multi_surface | Archetype 'hardcore' (Hardcore) has median score at 28.5% of overall median (threshold:... |
| B9 | non_viable_archetype | HIGH | economy_multi_surface | Archetype 'boost_focused' (Boost-Focused) has median score at 24.5% of overall median (... |
| B1 | underused_mechanics | MEDIUM | sigil_abilities | Action 'freeze' has only 0% usage rate across all archetypes and phases (threshold: <5%). |
| B2 | underused_mechanics | MEDIUM | sigil_abilities | Action 'theft' has only 2.3% usage rate across all archetypes and phases (threshold: <5%). |
| B3 | overpowered_mechanics | MEDIUM | boost_catalog | Archetype Hardcore earns 66.4% of coins while boosted (threshold: >60% per archetype). |
| B4 | overpowered_mechanics | MEDIUM | boost_catalog | Archetype Boost-Focused earns 69.7% of coins while boosted (threshold: >60% per archety... |
| B7 | sigil_scarcity | MEDIUM | sigil_drop_engine | PARTIAL: Season-column tuning applied, but full fix requires: Drop rate constants are i... |

## Scenarios

### `stage1-base_ubi_active_per_tick-04`

- Stage: `stage_1_single_knob`
- Categories: boost_related

```json
{
    "base_ubi_active_per_tick": 32
}
```

### `stage1-hoarding_idle_multiplier_fp-03`

- Stage: `stage_1_single_knob`
- Categories: hoarding_preservation_pressure

```json
{
    "hoarding_idle_multiplier_fp": 1339000
}
```

### `stage1-hoarding_tier2_rate_hourly_fp-01`

- Stage: `stage_1_single_knob`
- Categories: hoarding_preservation_pressure

```json
{
    "hoarding_tier2_rate_hourly_fp": 578
}
```

### `stage1-hoarding_tier3_rate_hourly_fp-02`

- Stage: `stage_1_single_knob`
- Categories: hoarding_preservation_pressure

```json
{
    "hoarding_tier3_rate_hourly_fp": 1177
}
```

### `stage1-market_affordability_bias_fp-10`

- Stage: `stage_1_single_knob`
- Categories: star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "market_affordability_bias_fp": 873000
}
```

### `stage1-starprice_max_downstep_fp-11`

- Stage: `stage_1_single_knob`
- Categories: star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "starprice_max_downstep_fp": 15811
}
```

### `stage1-starprice_reactivation_window_ticks-09`

- Stage: `stage_1_single_knob`
- Categories: star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "starprice_reactivation_window_ticks": 100
}
```

### `stage1-hoarding_min_factor_fp-07`

- Stage: `stage_1_single_knob`
- Categories: boost_related, hoarding_preservation_pressure

```json
{
    "hoarding_min_factor_fp": 93600
}
```

### `stage1-hoarding_window_ticks-08`

- Stage: `stage_1_single_knob`
- Categories: phase_timing, hoarding_preservation_pressure

```json
{
    "hoarding_window_ticks": 84672
}
```

### `stage1-target_spend_rate_per_tick-06`

- Stage: `stage_1_single_knob`
- Categories: boost_related, hoarding_preservation_pressure

```json
{
    "target_spend_rate_per_tick": 17
}
```

### `stage1-vault_config-05`

- Stage: `stage_1_single_knob`
- Categories: sigil_drop_tier_combine

```json
{
    "vault_config": "[{\"tier\":1,\"supply\":575,\"cost_table\":[{\"cost\":48,\"remaining\":1}]},{\"tier\":2,\"supply\":288,\"cost_table\":[{\"cost\":238,\"remaining\":1}]},{\"tier\":3,\"supply\":144,\"cost_table\":[{\"cost\":950,\"remaining\":1}]}]"
}
```

### `stage2-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "hoarding_tier2_rate_hourly_fp": 578
}
```

### `stage2-hoarding_idle_multiplier_fp-hoarding_tier3_rate_hourly_fp`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "hoarding_tier3_rate_hourly_fp": 1177
}
```

### `stage2-hoarding_idle_multiplier_fp-market_affordability_bias_fp`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "market_affordability_bias_fp": 873000
}
```

### `stage2-hoarding_idle_multiplier_fp-starprice_max_downstep_fp`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "starprice_max_downstep_fp": 15811
}
```

### `stage2-hoarding_idle_multiplier_fp-starprice_reactivation_window_ticks`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "starprice_reactivation_window_ticks": 100
}
```

### `stage2-hoarding_tier2_rate_hourly_fp-hoarding_tier3_rate_hourly_fp`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure

```json
{
    "hoarding_tier2_rate_hourly_fp": 578,
    "hoarding_tier3_rate_hourly_fp": 1177
}
```

### `stage2-hoarding_tier2_rate_hourly_fp-market_affordability_bias_fp`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_tier2_rate_hourly_fp": 578,
    "market_affordability_bias_fp": 873000
}
```

### `stage2-hoarding_tier2_rate_hourly_fp-starprice_max_downstep_fp`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_tier2_rate_hourly_fp": 578,
    "starprice_max_downstep_fp": 15811
}
```

### `stage2-hoarding_tier2_rate_hourly_fp-starprice_reactivation_window_ticks`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_tier2_rate_hourly_fp": 578,
    "starprice_reactivation_window_ticks": 100
}
```

### `stage2-hoarding_tier3_rate_hourly_fp-market_affordability_bias_fp`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_tier3_rate_hourly_fp": 1177,
    "market_affordability_bias_fp": 873000
}
```

### `stage2-hoarding_tier3_rate_hourly_fp-starprice_max_downstep_fp`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_tier3_rate_hourly_fp": 1177,
    "starprice_max_downstep_fp": 15811
}
```

### `stage2-hoarding_tier3_rate_hourly_fp-starprice_reactivation_window_ticks`

- Stage: `stage_2_pairwise`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_tier3_rate_hourly_fp": 1177,
    "starprice_reactivation_window_ticks": 100
}
```

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-hoarding_tier3_rate_hourly_fp`

- Stage: `stage_3_constrained_bundle`
- Categories: hoarding_preservation_pressure

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "hoarding_tier2_rate_hourly_fp": 578,
    "hoarding_tier3_rate_hourly_fp": 1177
}
```

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-market_affordability_bias_fp`

- Stage: `stage_3_constrained_bundle`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "hoarding_tier2_rate_hourly_fp": 578,
    "market_affordability_bias_fp": 873000
}
```

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-starprice_max_downstep_fp`

- Stage: `stage_3_constrained_bundle`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "hoarding_tier2_rate_hourly_fp": 578,
    "starprice_max_downstep_fp": 15811
}
```

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-starprice_reactivation_window_ticks`

- Stage: `stage_3_constrained_bundle`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "hoarding_tier2_rate_hourly_fp": 578,
    "starprice_reactivation_window_ticks": 100
}
```

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier3_rate_hourly_fp-market_affordability_bias_fp`

- Stage: `stage_3_constrained_bundle`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "hoarding_tier3_rate_hourly_fp": 1177,
    "market_affordability_bias_fp": 873000
}
```

### `stage3-hoarding_idle_multiplier_fp-hoarding_tier3_rate_hourly_fp-starprice_max_downstep_fp`

- Stage: `stage_3_constrained_bundle`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "hoarding_tier3_rate_hourly_fp": 1177,
    "starprice_max_downstep_fp": 15811
}
```

### `stage4-stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-hoarding_tier3_rate_hourly_fp`

- Stage: `stage_4_full_confirmation`
- Categories: hoarding_preservation_pressure

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "hoarding_tier2_rate_hourly_fp": 578,
    "hoarding_tier3_rate_hourly_fp": 1177
}
```

### `stage4-stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-market_affordability_bias_fp`

- Stage: `stage_4_full_confirmation`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "hoarding_tier2_rate_hourly_fp": 578,
    "market_affordability_bias_fp": 873000
}
```

### `stage4-stage3-hoarding_idle_multiplier_fp-hoarding_tier2_rate_hourly_fp-starprice_max_downstep_fp`

- Stage: `stage_4_full_confirmation`
- Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

```json
{
    "hoarding_idle_multiplier_fp": 1339000,
    "hoarding_tier2_rate_hourly_fp": 578,
    "starprice_max_downstep_fp": 15811
}
```

