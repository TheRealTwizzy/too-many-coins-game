# Economy Diagnosis Summary

Generated: 2026-04-27T06:17:05+00:00
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
- `boost_share_of_coin_delta`: 1.0037
- `coin_delta_top_vs_rest`: 122981.84
- `boosted_coin_delta`: 123441.37
- `players_in_top_quartile`: 438

**Threshold:** 0.4 | **Observed:** 1.0037

---

## MEDIUM Severity Findings

### [B1] underused_mechanics

Action 'freeze' has only 0.5% usage rate across all archetypes and phases (threshold: <5%).

**Evidence:**
- `action`: freeze
- `count`: 2654
- `grand_total`: 503043
- `share`: 0.0053

**Threshold:** 0.05 | **Observed:** 0.0053

**Affected phases:** EARLY, MID, LATE_ACTIVE, BLACKOUT

---

### [B2] underused_mechanics

Action 'theft' has only 1.9% usage rate across all archetypes and phases (threshold: <5%).

**Evidence:**
- `action`: theft
- `count`: 9309
- `grand_total`: 503043
- `share`: 0.0185

**Threshold:** 0.05 | **Observed:** 0.0185

**Affected phases:** EARLY, MID, LATE_ACTIVE, BLACKOUT

---

### [B4] overpowered_mechanics

Archetype Casual earns 99.8% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: casual
- `boost_coin_share`: 0.9979
- `mean_ticks_boosted`: 129228.67

**Threshold:** 0.6 | **Observed:** 0.9979

**Affected archetypes:** casual

---

### [B5] overpowered_mechanics

Archetype Regular earns 99.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: regular
- `boost_coin_share`: 0.9985
- `mean_ticks_boosted`: 152223

**Threshold:** 0.6 | **Observed:** 0.9985

**Affected archetypes:** regular

---

### [B6] overpowered_mechanics

Archetype Hardcore earns 99.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: hardcore
- `boost_coin_share`: 0.9994
- `mean_ticks_boosted`: 184237.53

**Threshold:** 0.6 | **Observed:** 0.9994

**Affected archetypes:** hardcore

---

### [B7] overpowered_mechanics

Archetype Hoarder earns 98.5% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: hoarder
- `boost_coin_share`: 0.9848
- `mean_ticks_boosted`: 122528.27

**Threshold:** 0.6 | **Observed:** 0.9848

**Affected archetypes:** hoarder

---

### [B8] overpowered_mechanics

Archetype Early Locker earns 99.8% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: early_locker
- `boost_coin_share`: 0.9981
- `mean_ticks_boosted`: 99253.07

**Threshold:** 0.6 | **Observed:** 0.9981

**Affected archetypes:** early_locker

---

### [B9] overpowered_mechanics

Archetype Late Deployer earns 99.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: late_deployer
- `boost_coin_share`: 0.9987
- `mean_ticks_boosted`: 175136.27

**Threshold:** 0.6 | **Observed:** 0.9987

**Affected archetypes:** late_deployer

---

### [B10] overpowered_mechanics

Archetype Boost-Focused earns 100% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: boost_focused
- `boost_coin_share`: 0.9996
- `mean_ticks_boosted`: 192019.2

**Threshold:** 0.6 | **Observed:** 0.9996

**Affected archetypes:** boost_focused

---

### [B11] overpowered_mechanics

Archetype Star-Focused earns 99.8% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: star_focused
- `boost_coin_share`: 0.9978
- `mean_ticks_boosted`: 141072.6

**Threshold:** 0.6 | **Observed:** 0.9978

**Affected archetypes:** star_focused

---

### [B12] overpowered_mechanics

Archetype Aggressive Sigil User earns 99.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: aggressive_sigil_user
- `boost_coin_share`: 0.9991
- `mean_ticks_boosted`: 163406.8

**Threshold:** 0.6 | **Observed:** 0.9991

**Affected archetypes:** aggressive_sigil_user

---

### [B13] overpowered_mechanics

Archetype Mostly Idle earns 99.7% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: mostly_idle
- `boost_coin_share`: 0.9971
- `mean_ticks_boosted`: 93282.73

**Threshold:** 0.6 | **Observed:** 0.9971

**Affected archetypes:** mostly_idle

---

### [B14] sigil_overabundance

Median total sigil inventory is 389.1 per player (threshold: >20, cap is 25).

**Evidence:**
- `median_total_per_player`: 389.1
- `per_archetype`: {"casual":306.8,"regular":357.4,"hardcore":463.6,"hoarder":677.6,"early_locker":236,"late_deployer":394.4,"boost_focused":437.9,"star_focused":416.1,"aggressive_sigil_user":383.8,"mostly_idle":226.6}

**Threshold:** 20 | **Observed:** 389.1

**Affected archetypes:** casual, regular, hardcore, hoarder, early_locker, late_deployer, boost_focused, star_focused, aggressive_sigil_user, mostly_idle

---

### [B15] phase_dead_zones

BLACKOUT phase has only 0% of total actions (threshold: <10%).

**Evidence:**
- `phase`: BLACKOUT
- `phase_total`: 43
- `share`: 0.0001
- `grand_total`: 503043

**Threshold:** 0.1 | **Observed:** 0.0001

**Affected phases:** BLACKOUT

---

---
*This report is auto-generated by `scripts/diagnose_economy.php` and is deterministic for a given baseline analysis input.*
