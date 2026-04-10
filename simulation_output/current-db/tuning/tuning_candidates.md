# Economy Tuning Candidates

Generated: 2026-04-10 20:44:04 UTC
Diagnosis source: `C:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\simulation_output\current-db\diagnosis\diagnosis_report.json`

## Summary

| Metric | Value |
|---|---|
| Findings processed | 12 |
| Findings tunable | 6 |
| Findings escalated | 6 |
| Findings skipped | 0 |
| Packages generated | 3 |
| Scenarios generated | 3 |

---

## Package: conservative

_Targets only HIGH-severity findings with minimal value changes and LOW risk._

Severity filter: HIGH  
Max risk: LOW  
Changes: 3

| # | Target | Current | Proposed | Risk | Mechanic | Finding |
|---|--------|---------|----------|------|----------|---------|
| C1 | `hoarding_tier2_rate_hourly_fp` | 500 | 575 | LOW | hoarding_sink | B11 |
| C2 | `hoarding_tier3_rate_hourly_fp` | 1000 | 1150 | LOW | hoarding_sink | B11 |
| C3 | `hoarding_idle_multiplier_fp` | 1250000 | 1312500 | LOW | hoarding_sink | B11 |

---

## Package: balanced

_Targets HIGH and MEDIUM-severity findings with moderate value changes._

Severity filter: HIGH, MEDIUM  
Max risk: MEDIUM  
Changes: 8

| # | Target | Current | Proposed | Risk | Mechanic | Finding |
|---|--------|---------|----------|------|----------|---------|
| C4 | `hoarding_tier2_rate_hourly_fp` | 500 | 625 | MEDIUM | hoarding_sink | B11 |
| C5 | `hoarding_tier3_rate_hourly_fp` | 1000 | 1250 | MEDIUM | hoarding_sink | B11 |
| C6 | `hoarding_idle_multiplier_fp` | 1250000 | 1437500 | LOW | hoarding_sink | B11 |
| C7 | `base_ubi_active_per_tick` | 30 | 35 | MEDIUM | ubi | B5,B6 |
| C8 | `vault_config` | [{"tier": 1, "supply": 500,... | [{"tier":1,"supply":650,"co... | LOW | sigil_vault | B7 |
| C9 | `target_spend_rate_per_tick` | 15 | 12 | MEDIUM | boost_economy | B10 |
| C10 | `hoarding_min_factor_fp` | 100000 | 130000 | MEDIUM | hoarding_sink | B10 |
| C11 | `hoarding_window_ticks` | 17280 | 14342 | MEDIUM | hoarding_sink | B12 |

---

## Package: aggressive

_Targets all findings with larger changes. May include MEDIUM-HIGH risk._

Severity filter: HIGH, MEDIUM, LOW  
Max risk: HIGH  
Changes: 8

| # | Target | Current | Proposed | Risk | Mechanic | Finding |
|---|--------|---------|----------|------|----------|---------|
| C12 | `hoarding_tier2_rate_hourly_fp` | 500 | 800 | HIGH | hoarding_sink | B11 |
| C13 | `hoarding_tier3_rate_hourly_fp` | 1000 | 1600 | HIGH | hoarding_sink | B11 |
| C14 | `hoarding_idle_multiplier_fp` | 1250000 | 1625000 | MEDIUM | hoarding_sink | B11 |
| C15 | `base_ubi_active_per_tick` | 30 | 40 | HIGH | ubi | B5,B6 |
| C16 | `vault_config` | [{"tier": 1, "supply": 500,... | [{"tier":1,"supply":750,"co... | MEDIUM | sigil_vault | B7 |
| C17 | `target_spend_rate_per_tick` | 15 | 11 | MEDIUM | boost_economy | B10 |
| C18 | `hoarding_min_factor_fp` | 100000 | 160000 | HIGH | hoarding_sink | B10 |
| C19 | `hoarding_window_ticks` | 17280 | 12960 | MEDIUM | hoarding_sink | B12 |

---

## Escalations (requires logic change)

| Finding | Category | Severity | Subsystem | Reason |
|---------|----------|----------|-----------|--------|
| B8 | non_viable_archetype | HIGH | economy_multi_surface | Archetype 'hardcore' (Hardcore) has median score at 28.5% of overall median (... |
| B9 | non_viable_archetype | HIGH | economy_multi_surface | Archetype 'boost_focused' (Boost-Focused) has median score at 24.5% of overal... |
| B1 | underused_mechanics | MEDIUM | sigil_abilities | Action 'freeze' has only 0% usage rate across all archetypes and phases (thre... |
| B2 | underused_mechanics | MEDIUM | sigil_abilities | Action 'theft' has only 2.3% usage rate across all archetypes and phases (thr... |
| B3 | overpowered_mechanics | MEDIUM | boost_catalog | Archetype Hardcore earns 66.4% of coins while boosted (threshold: >60% per ar... |
| B4 | overpowered_mechanics | MEDIUM | boost_catalog | Archetype Boost-Focused earns 69.7% of coins while boosted (threshold: >60% p... |
| B7 | sigil_scarcity (PARTIAL) | MEDIUM | sigil_drop_engine | PARTIAL: Season-column tuning applied, but full fix requires: Drop rate const... |

---

## Registered Scenarios (for Phase D verification)

### `tuning-conservative-v1`

_Phase C tuning scenario (conservative): Targets only HIGH-severity findings with minimal value changes and LOW risk._

Categories: hoarding_preservation_pressure

Overrides:

```json
{
    "hoarding_tier2_rate_hourly_fp": 575,
    "hoarding_tier3_rate_hourly_fp": 1150,
    "hoarding_idle_multiplier_fp": 1312500
}
```

### `tuning-balanced-v1`

_Phase C tuning scenario (balanced): Targets HIGH and MEDIUM-severity findings with moderate value changes._

Categories: hoarding_preservation_pressure, boost_related, sigil_drop_tier_combine, phase_timing

Overrides:

```json
{
    "hoarding_tier2_rate_hourly_fp": 625,
    "hoarding_tier3_rate_hourly_fp": 1250,
    "hoarding_idle_multiplier_fp": 1437500,
    "base_ubi_active_per_tick": 35,
    "vault_config": "[{\"tier\":1,\"supply\":650,\"cost_table\":[{\"cost\":45,\"remaining\":1}]},{\"tier\":2,\"supply\":325,\"cost_table\":[{\"cost\":225,\"remaining\":1}]},{\"tier\":3,\"supply\":163,\"cost_table\":[{\"cost\":900,\"remaining\":1}]}]",
    "target_spend_rate_per_tick": 12,
    "hoarding_min_factor_fp": 130000,
    "hoarding_window_ticks": 14342
}
```

### `tuning-aggressive-v1`

_Phase C tuning scenario (aggressive): Targets all findings with larger changes. May include MEDIUM-HIGH risk._

Categories: hoarding_preservation_pressure, boost_related, sigil_drop_tier_combine, phase_timing

Overrides:

```json
{
    "hoarding_tier2_rate_hourly_fp": 800,
    "hoarding_tier3_rate_hourly_fp": 1600,
    "hoarding_idle_multiplier_fp": 1625000,
    "base_ubi_active_per_tick": 40,
    "vault_config": "[{\"tier\":1,\"supply\":750,\"cost_table\":[{\"cost\":43,\"remaining\":1}]},{\"tier\":2,\"supply\":375,\"cost_table\":[{\"cost\":213,\"remaining\":1}]},{\"tier\":3,\"supply\":188,\"cost_table\":[{\"cost\":850,\"remaining\":1}]}]",
    "target_spend_rate_per_tick": 11,
    "hoarding_min_factor_fp": 160000,
    "hoarding_window_ticks": 12960
}
```

---

## Next Steps

1. Review packages and escalations
2. Run Phase D verification sweep:
   ```
   php scripts/simulate_policy_sweep.php \
     --seed=tuning-verify-v1 \
     --scenarios=tuning-conservative-v1,tuning-balanced-v1,tuning-aggressive-v1 \
     --include-baseline=1 --simulators=B,C --players-per-archetype=10 --seasons=12
   ```
3. Compare results with `compare_simulation_results.php`
