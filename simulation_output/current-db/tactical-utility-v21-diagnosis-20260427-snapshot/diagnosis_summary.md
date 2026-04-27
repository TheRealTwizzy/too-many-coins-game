# Economy Diagnosis Summary

Generated: 2026-04-27T09:58:07+00:00
Source: baseline_analysis_report.json
Sim B runs: 15 | Sim C runs: 6

## Overview

Total findings: **15**

| Severity | Count |
|---|---|
| HIGH | 1 |
| MEDIUM | 14 |
| LOW | 0 |

## HIGH Severity Findings

### [B3] overpowered_mechanics

Boost mechanic contributes 98.1% of coin earning delta between top-quartile and remaining players (threshold: >40%).

**Evidence:**
- `boost_share_of_coin_delta`: 0.9811
- `coin_delta_top_vs_rest`: 157104.61
- `boosted_coin_delta`: 154139.49
- `players_in_top_quartile`: 438

**Threshold:** 0.4 | **Observed:** 0.9811

---

## MEDIUM Severity Findings

### [B1] underused_mechanics

Action 'freeze' has only 4.5% usage rate across all archetypes and phases (threshold: <5%).

**Evidence:**
- `action`: freeze
- `count`: 5181
- `grand_total`: 115030
- `share`: 0.045

**Threshold:** 0.05 | **Observed:** 0.045

**Affected phases:** EARLY, MID, LATE_ACTIVE, BLACKOUT

---

### [B2] underused_mechanics

Action 'theft' has only 1.5% usage rate across all archetypes and phases (threshold: <5%).

**Evidence:**
- `action`: theft
- `count`: 1695
- `grand_total`: 115030
- `share`: 0.0147

**Threshold:** 0.05 | **Observed:** 0.0147

**Affected phases:** EARLY, MID, LATE_ACTIVE, BLACKOUT

---

### [B4] overpowered_mechanics

Archetype Casual earns 91.3% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: casual
- `boost_coin_share`: 0.9132
- `mean_ticks_boosted`: 85146.93

**Threshold:** 0.6 | **Observed:** 0.9132

**Affected archetypes:** casual

---

### [B5] overpowered_mechanics

Archetype Regular earns 93.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: regular
- `boost_coin_share`: 0.9392
- `mean_ticks_boosted`: 126011.33

**Threshold:** 0.6 | **Observed:** 0.9392

**Affected archetypes:** regular

---

### [B6] overpowered_mechanics

Archetype Hardcore earns 95.6% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: hardcore
- `boost_coin_share`: 0.9564
- `mean_ticks_boosted`: 158502.13

**Threshold:** 0.6 | **Observed:** 0.9564

**Affected archetypes:** hardcore

---

### [B7] overpowered_mechanics

Archetype Hoarder earns 88.5% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: hoarder
- `boost_coin_share`: 0.8849
- `mean_ticks_boosted`: 108689.33

**Threshold:** 0.6 | **Observed:** 0.8849

**Affected archetypes:** hoarder

---

### [B8] overpowered_mechanics

Archetype Early Locker earns 95% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: early_locker
- `boost_coin_share`: 0.9499
- `mean_ticks_boosted`: 86444.8

**Threshold:** 0.6 | **Observed:** 0.9499

**Affected archetypes:** early_locker

---

### [B9] overpowered_mechanics

Archetype Late Deployer earns 92.6% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: late_deployer
- `boost_coin_share`: 0.9264
- `mean_ticks_boosted`: 126266.67

**Threshold:** 0.6 | **Observed:** 0.9264

**Affected archetypes:** late_deployer

---

### [B10] overpowered_mechanics

Archetype Boost-Focused earns 96.1% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: boost_focused
- `boost_coin_share`: 0.9612
- `mean_ticks_boosted`: 158103

**Threshold:** 0.6 | **Observed:** 0.9612

**Affected archetypes:** boost_focused

---

### [B11] overpowered_mechanics

Archetype Star-Focused earns 91.7% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: star_focused
- `boost_coin_share`: 0.9167
- `mean_ticks_boosted`: 117759.93

**Threshold:** 0.6 | **Observed:** 0.9167

**Affected archetypes:** star_focused

---

### [B12] overpowered_mechanics

Archetype Aggressive Sigil User earns 95.2% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: aggressive_sigil_user
- `boost_coin_share`: 0.9515
- `mean_ticks_boosted`: 150348.53

**Threshold:** 0.6 | **Observed:** 0.9515

**Affected archetypes:** aggressive_sigil_user

---

### [B13] overpowered_mechanics

Archetype Mostly Idle earns 90.7% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: mostly_idle
- `boost_coin_share`: 0.9071
- `mean_ticks_boosted`: 62914.4

**Threshold:** 0.6 | **Observed:** 0.9071

**Affected archetypes:** mostly_idle

---

### [B14] sigil_overabundance

Median total sigil inventory is 83.5 per player (threshold: >20, cap is 25).

**Evidence:**
- `median_total_per_player`: 83.5
- `per_archetype`: {"casual":48.4,"regular":78.3,"hardcore":111.8,"hoarder":94.8,"early_locker":55.4,"late_deployer":74.3,"boost_focused":101,"star_focused":88.8,"aggressive_sigil_user":99,"mostly_idle":34.7}

**Threshold:** 20 | **Observed:** 83.5

**Affected archetypes:** casual, regular, hardcore, hoarder, early_locker, late_deployer, boost_focused, star_focused, aggressive_sigil_user, mostly_idle

---

### [B15] phase_dead_zones

BLACKOUT phase has only 0% of total actions (threshold: <10%).

**Evidence:**
- `phase`: BLACKOUT
- `phase_total`: 12
- `share`: 0.0001
- `grand_total`: 115030

**Threshold:** 0.1 | **Observed:** 0.0001

**Affected phases:** BLACKOUT

---

---
*This report is auto-generated by `scripts/diagnose_economy.php` and is deterministic for a given baseline analysis input.*
