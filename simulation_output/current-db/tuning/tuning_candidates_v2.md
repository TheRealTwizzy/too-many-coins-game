# Economy Tuning Candidates (v2)

Generated: 2026-04-10 21:36:53 UTC
Diagnosis source: `C:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\simulation_output\current-db\diagnosis\diagnosis_report.json`
Tuning version: v2

## Summary

| Metric | Value |
|---|---|
| Findings processed | 12 |
| Findings tunable | 6 |
| Findings escalated | 6 |
| Findings skipped | 0 |
| Packages generated | 3 |
| Scenarios generated | 3 |

## Regression Mitigation Strategy (v2)

All v1 packages were REJECTED on all seeds due to regression flags.
v2 applies the following mitigations:

1. **Dampened hoarding drain** — multipliers reduced ~50% from v1 magnitudes
2. **Lock-in counterweights** — `starprice_reactivation_window_ticks` ↑ and `market_affordability_bias_fp` ↓
3. **Expiry softening** — `starprice_max_downstep_fp` ↑ so prices can drop faster when demand falls
4. **Multi-axis approach** — packages bundle hoarding drain with compensating lock-in/pricing changes

| Regression Flag | v1 Rate | v2 Target |
|---|---|---|
| skip_rejoin_exploit_worsened | 7/9 SimC | ≤3/9 |
| dominant_archetype_shifted | 9/18 | ≤4/18 |
| lock_in_down_but_expiry_dominance_up | 5/9 SimB | ≤1/9 |
| long_run_concentration_worsened | 3/9 SimC | 0/9 |

---

## Package: conservative

_Targets only HIGH-severity findings with minimal value changes and LOW risk. (v2: regression-mitigated, multi-axis)_

Severity filter: HIGH  
Max risk: LOW  
Changes: 6

| # | Target | Current | Proposed | Risk | Mechanic | Finding |
|---|--------|---------|----------|------|----------|---------|
| C1 | `hoarding_tier2_rate_hourly_fp` | 500 | 535 | LOW | hoarding_sink | B11 |
| C2 | `hoarding_tier3_rate_hourly_fp` | 1000 | 1070 | LOW | hoarding_sink | B11 |
| C3 | `hoarding_idle_multiplier_fp` | 1250000 | 1287500 | LOW | hoarding_sink | B11 |
| C4 | `starprice_reactivation_window_ticks` | 75 | 81 | LOW | star_pricing | regression_counterweight |
| C5 | `market_affordability_bias_fp` | 1000000 | 970000 | LOW | star_pricing | regression_counterweight |
| C6 | `starprice_max_downstep_fp` | 12000 | 12960 | LOW | star_pricing | regression_counterweight |

---

## Package: balanced

_Targets HIGH and MEDIUM-severity findings with moderate value changes. (v2: regression-mitigated, multi-axis)_

Severity filter: HIGH, MEDIUM  
Max risk: MEDIUM  
Changes: 11

| # | Target | Current | Proposed | Risk | Mechanic | Finding |
|---|--------|---------|----------|------|----------|---------|
| C7 | `hoarding_tier2_rate_hourly_fp` | 500 | 575 | LOW | hoarding_sink | B11 |
| C8 | `hoarding_tier3_rate_hourly_fp` | 1000 | 1150 | LOW | hoarding_sink | B11 |
| C9 | `hoarding_idle_multiplier_fp` | 1250000 | 1350000 | LOW | hoarding_sink | B11 |
| C10 | `base_ubi_active_per_tick` | 30 | 35 | MEDIUM | ubi | B5,B6 |
| C11 | `vault_config` | [{"tier": 1, "supply": 500,... | [{"tier":1,"supply":650,"co... | LOW | sigil_vault | B7 |
| C12 | `target_spend_rate_per_tick` | 15 | 13 | LOW | boost_economy | B10 |
| C13 | `hoarding_min_factor_fp` | 100000 | 115000 | LOW | hoarding_sink | B10 |
| C14 | `hoarding_window_ticks` | 17280 | 15206 | LOW | hoarding_sink | B12 |
| C15 | `starprice_reactivation_window_ticks` | 75 | 86 | LOW | star_pricing | regression_counterweight |
| C16 | `market_affordability_bias_fp` | 1000000 | 930000 | LOW | star_pricing | regression_counterweight |
| C17 | `starprice_max_downstep_fp` | 12000 | 13800 | LOW | star_pricing | regression_counterweight |

---

## Package: aggressive

_Targets all findings with larger changes. May include MEDIUM-HIGH risk. (v2: regression-mitigated, multi-axis)_

Severity filter: HIGH, MEDIUM, LOW  
Max risk: HIGH  
Changes: 11

| # | Target | Current | Proposed | Risk | Mechanic | Finding |
|---|--------|---------|----------|------|----------|---------|
| C18 | `hoarding_tier2_rate_hourly_fp` | 500 | 625 | MEDIUM | hoarding_sink | B11 |
| C19 | `hoarding_tier3_rate_hourly_fp` | 1000 | 1250 | MEDIUM | hoarding_sink | B11 |
| C20 | `hoarding_idle_multiplier_fp` | 1250000 | 1437500 | LOW | hoarding_sink | B11 |
| C21 | `base_ubi_active_per_tick` | 30 | 40 | HIGH | ubi | B5,B6 |
| C22 | `vault_config` | [{"tier": 1, "supply": 500,... | [{"tier":1,"supply":750,"co... | MEDIUM | sigil_vault | B7 |
| C23 | `target_spend_rate_per_tick` | 15 | 12 | MEDIUM | boost_economy | B10 |
| C24 | `hoarding_min_factor_fp` | 100000 | 130000 | MEDIUM | hoarding_sink | B10 |
| C25 | `hoarding_window_ticks` | 17280 | 13824 | MEDIUM | hoarding_sink | B12 |
| C26 | `starprice_reactivation_window_ticks` | 75 | 94 | MEDIUM | star_pricing | regression_counterweight |
| C27 | `market_affordability_bias_fp` | 1000000 | 880000 | LOW | star_pricing | regression_counterweight |
| C28 | `starprice_max_downstep_fp` | 12000 | 15000 | MEDIUM | star_pricing | regression_counterweight |

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

### `tuning-conservative-v2`

_Phase C tuning scenario (conservative): Targets only HIGH-severity findings with minimal value changes and LOW risk. (v2: regression-mitigated, multi-axis)_

Categories: hoarding_preservation_pressure, star_conversion_pricing, lock_in_expiry_incentives

Overrides:

```json
{
    "hoarding_tier2_rate_hourly_fp": 535,
    "hoarding_tier3_rate_hourly_fp": 1070,
    "hoarding_idle_multiplier_fp": 1287500,
    "starprice_reactivation_window_ticks": 81,
    "market_affordability_bias_fp": 970000,
    "starprice_max_downstep_fp": 12960
}
```

### `tuning-balanced-v2`

_Phase C tuning scenario (balanced): Targets HIGH and MEDIUM-severity findings with moderate value changes. (v2: regression-mitigated, multi-axis)_

Categories: hoarding_preservation_pressure, boost_related, sigil_drop_tier_combine, phase_timing, star_conversion_pricing, lock_in_expiry_incentives

Overrides:

```json
{
    "hoarding_tier2_rate_hourly_fp": 575,
    "hoarding_tier3_rate_hourly_fp": 1150,
    "hoarding_idle_multiplier_fp": 1350000,
    "base_ubi_active_per_tick": 35,
    "vault_config": "[{\"tier\":1,\"supply\":650,\"cost_table\":[{\"cost\":45,\"remaining\":1}]},{\"tier\":2,\"supply\":325,\"cost_table\":[{\"cost\":225,\"remaining\":1}]},{\"tier\":3,\"supply\":163,\"cost_table\":[{\"cost\":900,\"remaining\":1}]}]",
    "target_spend_rate_per_tick": 13,
    "hoarding_min_factor_fp": 115000,
    "hoarding_window_ticks": 15206,
    "starprice_reactivation_window_ticks": 86,
    "market_affordability_bias_fp": 930000,
    "starprice_max_downstep_fp": 13800
}
```

### `tuning-aggressive-v2`

_Phase C tuning scenario (aggressive): Targets all findings with larger changes. May include MEDIUM-HIGH risk. (v2: regression-mitigated, multi-axis)_

Categories: hoarding_preservation_pressure, boost_related, sigil_drop_tier_combine, phase_timing, star_conversion_pricing, lock_in_expiry_incentives

Overrides:

```json
{
    "hoarding_tier2_rate_hourly_fp": 625,
    "hoarding_tier3_rate_hourly_fp": 1250,
    "hoarding_idle_multiplier_fp": 1437500,
    "base_ubi_active_per_tick": 40,
    "vault_config": "[{\"tier\":1,\"supply\":750,\"cost_table\":[{\"cost\":43,\"remaining\":1}]},{\"tier\":2,\"supply\":375,\"cost_table\":[{\"cost\":213,\"remaining\":1}]},{\"tier\":3,\"supply\":188,\"cost_table\":[{\"cost\":850,\"remaining\":1}]}]",
    "target_spend_rate_per_tick": 12,
    "hoarding_min_factor_fp": 130000,
    "hoarding_window_ticks": 13824,
    "starprice_reactivation_window_ticks": 94,
    "market_affordability_bias_fp": 880000,
    "starprice_max_downstep_fp": 15000
}
```

---

## Next Steps

1. Review packages and escalations
2. Run Phase D verification sweep:
   ```
   php scripts/simulate_policy_sweep.php \
     --seed=tuning-verify-v2 \
     --scenarios=tuning-conservative-v2,tuning-balanced-v2,tuning-aggressive-v2 \
     --include-baseline=1 --simulators=B,C --players-per-archetype=10 --seasons=12
   ```
3. Compare results with `compare_simulation_results.php`
