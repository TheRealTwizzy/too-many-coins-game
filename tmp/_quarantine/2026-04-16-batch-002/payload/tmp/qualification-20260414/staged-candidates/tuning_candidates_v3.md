# Economy Tuning Candidates

Generated: 2026-04-15 01:20:01 UTC
Diagnosis source: `C:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\simulation_output\current-db\diagnosis\diagnosis_report.json`
Tuning version: v3

## Summary

| Metric | Value |
|---|---|
| Findings processed | 12 |
| Findings tunable | 6 |
| Findings escalated | 6 |
| Candidates generated | 3 |
| Scenarios generated | 3 |
| Suppressed candidate families | 8 |

## Baseline Constraints

| Feature Flag | Baseline Value | Enabled |
|---|---|---|
| `season.hoarding_sink_enabled` | 0 | no |

## Stage Overview

| Stage | Candidates | Blocked from next stage | Suppressed before generation |
|---|---|---|---|
| `stage_1_single_knob` | 3 | 1 | 8 |
| `stage_2_pairwise` | 0 | 0 | 0 |
| `stage_3_constrained_bundle` | 0 | 0 | 0 |
| `stage_4_full_confirmation` | 0 | 0 | 0 |

## Suppressed Families

| Stage | Family | Target | Reason |
|---|---|---|---|
| `stage_1_single_knob` | `hoarding_advantage` | `hoarding_tier2_rate_hourly_fp` | Knob is outside the active search space because `season.hoarding_sink_enabled` resolves... |
| `stage_1_single_knob` | `hoarding_advantage` | `hoarding_tier3_rate_hourly_fp` | Knob is outside the active search space because `season.hoarding_sink_enabled` resolves... |
| `stage_1_single_knob` | `hoarding_advantage` | `hoarding_idle_multiplier_fp` | Knob is outside the active search space because `season.hoarding_sink_enabled` resolves... |
| `stage_1_single_knob` | `boost_roi_imbalance` | `hoarding_min_factor_fp` | Subsystem is disabled in the baseline because `season.hoarding_sink_enabled` resolves t... |
| `stage_1_single_knob` | `phase_dead_zones` | `hoarding_window_ticks` | Subsystem is disabled in the baseline because `season.hoarding_sink_enabled` resolves t... |
| `stage_1_single_knob` | `lock_in_support` | `starprice_reactivation_window_ticks` | Counterweight dimension has no active primary trigger lane after baseline search-space ... |
| `stage_1_single_knob` | `lock_in_support` | `market_affordability_bias_fp` | Counterweight dimension has no active primary trigger lane after baseline search-space ... |
| `stage_1_single_knob` | `expiry_softening` | `starprice_max_downstep_fp` | Counterweight dimension has no active primary trigger lane after baseline search-space ... |

## stage_1_single_knob

### `stage1-base_ubi_active_per_tick-01`

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

### `stage1-target_spend_rate_per_tick-03`

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

### `stage1-vault_config-02`

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

### `stage1-base_ubi_active_per_tick-01`

- Stage: `stage_1_single_knob`
- Categories: boost_related

```json
{
    "base_ubi_active_per_tick": 32
}
```

### `stage1-target_spend_rate_per_tick-03`

- Stage: `stage_1_single_knob`
- Categories: boost_related, hoarding_preservation_pressure

```json
{
    "target_spend_rate_per_tick": 17
}
```

### `stage1-vault_config-02`

- Stage: `stage_1_single_knob`
- Categories: sigil_drop_tier_combine

```json
{
    "vault_config": "[{\"tier\":1,\"supply\":575,\"cost_table\":[{\"cost\":48,\"remaining\":1}]},{\"tier\":2,\"supply\":288,\"cost_table\":[{\"cost\":238,\"remaining\":1}]},{\"tier\":3,\"supply\":144,\"cost_table\":[{\"cost\":950,\"remaining\":1}]}]"
}
```

