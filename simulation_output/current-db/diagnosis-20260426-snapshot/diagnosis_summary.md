# Economy Diagnosis Summary

Generated: 2026-04-26T17:36:59+00:00
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

Boost mechanic contributes 100.4% of coin earning delta between top-quartile and remaining players (threshold: >40%).

**Evidence:**
- `boost_share_of_coin_delta`: 1.0043
- `coin_delta_top_vs_rest`: 96908.87
- `boosted_coin_delta`: 97326.41
- `players_in_top_quartile`: 438

**Threshold:** 0.4 | **Observed:** 1.0043

---

## MEDIUM Severity Findings

### [B1] underused_mechanics

Action 'freeze' has only 0.7% usage rate across all archetypes and phases (threshold: <5%).

**Evidence:**
- `action`: freeze
- `count`: 10876
- `grand_total`: 1459466
- `share`: 0.0075

**Threshold:** 0.05 | **Observed:** 0.0075

**Affected phases:** EARLY, MID, LATE_ACTIVE, BLACKOUT

---

### [B2] underused_mechanics

Action 'theft' has only 1.1% usage rate across all archetypes and phases (threshold: <5%).

**Evidence:**
- `action`: theft
- `count`: 15501
- `grand_total`: 1459466
- `share`: 0.0106

**Threshold:** 0.05 | **Observed:** 0.0106

**Affected phases:** EARLY, MID, LATE_ACTIVE, BLACKOUT

---

### [B4] overpowered_mechanics

Archetype Casual earns 99.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: casual
- `boost_coin_share`: 0.999
- `mean_ticks_boosted`: 133359.87

**Threshold:** 0.6 | **Observed:** 0.999

**Affected archetypes:** casual

---

### [B5] overpowered_mechanics

Archetype Regular earns 99.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: regular
- `boost_coin_share`: 0.999
- `mean_ticks_boosted`: 149525.53

**Threshold:** 0.6 | **Observed:** 0.999

**Affected archetypes:** regular

---

### [B6] overpowered_mechanics

Archetype Hardcore earns 99.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: hardcore
- `boost_coin_share`: 0.9993
- `mean_ticks_boosted`: 191016.93

**Threshold:** 0.6 | **Observed:** 0.9993

**Affected archetypes:** hardcore

---

### [B7] overpowered_mechanics

Archetype Hoarder earns 98.8% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: hoarder
- `boost_coin_share`: 0.9875
- `mean_ticks_boosted`: 125151.47

**Threshold:** 0.6 | **Observed:** 0.9875

**Affected archetypes:** hoarder

---

### [B8] overpowered_mechanics

Archetype Early Locker earns 99.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: early_locker
- `boost_coin_share`: 0.9986
- `mean_ticks_boosted`: 95282.07

**Threshold:** 0.6 | **Observed:** 0.9986

**Affected archetypes:** early_locker

---

### [B9] overpowered_mechanics

Archetype Late Deployer earns 99.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: late_deployer
- `boost_coin_share`: 0.9994
- `mean_ticks_boosted`: 206372.13

**Threshold:** 0.6 | **Observed:** 0.9994

**Affected archetypes:** late_deployer

---

### [B10] overpowered_mechanics

Archetype Boost-Focused earns 100% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: boost_focused
- `boost_coin_share`: 0.9996
- `mean_ticks_boosted`: 216538.4

**Threshold:** 0.6 | **Observed:** 0.9996

**Affected archetypes:** boost_focused

---

### [B11] overpowered_mechanics

Archetype Star-Focused earns 99.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: star_focused
- `boost_coin_share`: 0.9987
- `mean_ticks_boosted`: 143629.4

**Threshold:** 0.6 | **Observed:** 0.9987

**Affected archetypes:** star_focused

---

### [B12] overpowered_mechanics

Archetype Aggressive Sigil User earns 99.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: aggressive_sigil_user
- `boost_coin_share`: 0.9993
- `mean_ticks_boosted`: 169682.33

**Threshold:** 0.6 | **Observed:** 0.9993

**Affected archetypes:** aggressive_sigil_user

---

### [B13] overpowered_mechanics

Archetype Mostly Idle earns 99.8% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: mostly_idle
- `boost_coin_share`: 0.9982
- `mean_ticks_boosted`: 95294.2

**Threshold:** 0.6 | **Observed:** 0.9982

**Affected archetypes:** mostly_idle

---

### [B14] sigil_overabundance

Median total sigil inventory is 1153.9 per player (threshold: >20, cap is 25).

**Evidence:**
- `median_total_per_player`: 1153.9
- `per_archetype`: {"casual":719.9,"regular":1068.1,"hardcore":1755.5,"hoarder":1193.1,"early_locker":751.8,"late_deployer":1137.9,"boost_focused":1715.8,"star_focused":1169.8,"aggressive_sigil_user":1388.9,"mostly_idle":466.7}

**Threshold:** 20 | **Observed:** 1153.9

**Affected archetypes:** casual, regular, hardcore, hoarder, early_locker, late_deployer, boost_focused, star_focused, aggressive_sigil_user, mostly_idle

---

### [B15] phase_dead_zones

BLACKOUT phase has only 0% of total actions (threshold: <10%).

**Evidence:**
- `phase`: BLACKOUT
- `phase_total`: 258
- `share`: 0.0002
- `grand_total`: 1459466

**Threshold:** 0.1 | **Observed:** 0.0002

**Affected phases:** BLACKOUT

---

---
*This report is auto-generated by `scripts/diagnose_economy.php` and is deterministic for a given baseline analysis input.*
