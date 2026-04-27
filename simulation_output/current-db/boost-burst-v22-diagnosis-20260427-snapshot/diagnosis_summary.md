# Economy Diagnosis Summary

Generated: 2026-04-27T13:52:09+00:00
Source: baseline_analysis_report.json
Sim B runs: 15 | Sim C runs: 6

## Overview

Total findings: **16**

| Severity | Count |
|---|---|
| HIGH | 1 |
| MEDIUM | 15 |
| LOW | 0 |

## HIGH Severity Findings

### [B4] overpowered_mechanics

Boost mechanic contributes 83.9% of coin earning delta between top-quartile and remaining players (threshold: >40%).

**Evidence:**
- `boost_share_of_coin_delta`: 0.8394
- `coin_delta_top_vs_rest`: 113427.46
- `boosted_coin_delta`: 95214.72
- `players_in_top_quartile`: 438

**Threshold:** 0.4 | **Observed:** 0.8394

---

## MEDIUM Severity Findings

### [B1] underused_mechanics

Action 'combine' has only 4.9% usage rate across all archetypes and phases (threshold: <5%).

**Evidence:**
- `action`: combine
- `count`: 6232
- `grand_total`: 127698
- `share`: 0.0488

**Threshold:** 0.05 | **Observed:** 0.0488

**Affected phases:** EARLY, MID, LATE_ACTIVE, BLACKOUT

---

### [B2] underused_mechanics

Action 'freeze' has only 4.3% usage rate across all archetypes and phases (threshold: <5%).

**Evidence:**
- `action`: freeze
- `count`: 5465
- `grand_total`: 127698
- `share`: 0.0428

**Threshold:** 0.05 | **Observed:** 0.0428

**Affected phases:** EARLY, MID, LATE_ACTIVE, BLACKOUT

---

### [B3] underused_mechanics

Action 'theft' has only 1.7% usage rate across all archetypes and phases (threshold: <5%).

**Evidence:**
- `action`: theft
- `count`: 2210
- `grand_total`: 127698
- `share`: 0.0173

**Threshold:** 0.05 | **Observed:** 0.0173

**Affected phases:** EARLY, MID, LATE_ACTIVE, BLACKOUT

---

### [B5] overpowered_mechanics

Archetype Casual earns 68% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: casual
- `boost_coin_share`: 0.6795
- `mean_ticks_boosted`: 52387.47

**Threshold:** 0.6 | **Observed:** 0.6795

**Affected archetypes:** casual

---

### [B6] overpowered_mechanics

Archetype Regular earns 73.9% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: regular
- `boost_coin_share`: 0.7385
- `mean_ticks_boosted`: 81239.33

**Threshold:** 0.6 | **Observed:** 0.7385

**Affected archetypes:** regular

---

### [B7] overpowered_mechanics

Archetype Hardcore earns 79.1% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: hardcore
- `boost_coin_share`: 0.7908
- `mean_ticks_boosted`: 116396.53

**Threshold:** 0.6 | **Observed:** 0.7908

**Affected archetypes:** hardcore

---

### [B8] overpowered_mechanics

Archetype Hoarder earns 63.8% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: hoarder
- `boost_coin_share`: 0.6379
- `mean_ticks_boosted`: 67539.07

**Threshold:** 0.6 | **Observed:** 0.6379

**Affected archetypes:** hoarder

---

### [B9] overpowered_mechanics

Archetype Early Locker earns 78.1% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: early_locker
- `boost_coin_share`: 0.7814
- `mean_ticks_boosted`: 57567.27

**Threshold:** 0.6 | **Observed:** 0.7814

**Affected archetypes:** early_locker

---

### [B10] overpowered_mechanics

Archetype Late Deployer earns 70.6% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: late_deployer
- `boost_coin_share`: 0.7062
- `mean_ticks_boosted`: 79830.33

**Threshold:** 0.6 | **Observed:** 0.7062

**Affected archetypes:** late_deployer

---

### [B11] overpowered_mechanics

Archetype Boost-Focused earns 79.7% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: boost_focused
- `boost_coin_share`: 0.7974
- `mean_ticks_boosted`: 115036.87

**Threshold:** 0.6 | **Observed:** 0.7974

**Affected archetypes:** boost_focused

---

### [B12] overpowered_mechanics

Archetype Star-Focused earns 69.2% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: star_focused
- `boost_coin_share`: 0.6918
- `mean_ticks_boosted`: 76550.33

**Threshold:** 0.6 | **Observed:** 0.6918

**Affected archetypes:** star_focused

---

### [B13] overpowered_mechanics

Archetype Aggressive Sigil User earns 77.4% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: aggressive_sigil_user
- `boost_coin_share`: 0.7737
- `mean_ticks_boosted`: 105634.73

**Threshold:** 0.6 | **Observed:** 0.7737

**Affected archetypes:** aggressive_sigil_user

---

### [B14] overpowered_mechanics

Archetype Mostly Idle earns 68.4% of coins while boosted (threshold: >60% per archetype).

**Evidence:**
- `archetype`: mostly_idle
- `boost_coin_share`: 0.6838
- `mean_ticks_boosted`: 35670.13

**Threshold:** 0.6 | **Observed:** 0.6838

**Affected archetypes:** mostly_idle

---

### [B15] sigil_overabundance

Median total sigil inventory is 87.7 per player (threshold: >20, cap is 25).

**Evidence:**
- `median_total_per_player`: 87.7
- `per_archetype`: {"casual":50.8,"regular":83.5,"hardcore":133.5,"hoarder":97.5,"early_locker":58.4,"late_deployer":81.9,"boost_focused":122,"star_focused":92,"aggressive_sigil_user":112.4,"mostly_idle":34.7}

**Threshold:** 20 | **Observed:** 87.7

**Affected archetypes:** casual, regular, hardcore, hoarder, early_locker, late_deployer, boost_focused, star_focused, aggressive_sigil_user, mostly_idle

---

### [B16] phase_dead_zones

BLACKOUT phase has only 0% of total actions (threshold: <10%).

**Evidence:**
- `phase`: BLACKOUT
- `phase_total`: 14
- `share`: 0.0001
- `grand_total`: 127698

**Threshold:** 0.1 | **Observed:** 0.0001

**Affected phases:** BLACKOUT

---

---
*This report is auto-generated by `scripts/diagnose_economy.php` and is deterministic for a given baseline analysis input.*
