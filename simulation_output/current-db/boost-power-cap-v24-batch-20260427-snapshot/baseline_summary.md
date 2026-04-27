# Baseline Analysis Summary

Generated: 2026-04-27T18:15:15+00:00
Sim B runs: 15 | Sim C runs: 6

## 1. Lock-In vs Expiry

- Overall lock-in rate: **66.5%** (n=1750)
- Overall natural expiry rate: **33.5%**

**Lock-in timing distribution:**
- EARLY: 0.0%
- MID: 26.8%
- LATE_ACTIVE: 33.8%
- BLACKOUT: 5.9%
- NONE: 33.5%

**By archetype:**
| Archetype | Lock-in Rate | Expiry Rate | Sample |
|---|---|---|---|
| Casual | 93.1% | 6.9% | 175 |
| Regular | 74.3% | 25.7% | 175 |
| Hardcore | 14.3% | 85.7% | 175 |
| Hoarder | 84.6% | 15.4% | 175 |
| Early Locker | 100.0% | 0.0% | 175 |
| Late Deployer | 33.1% | 66.9% | 175 |
| Boost-Focused | 34.9% | 65.1% | 175 |
| Star-Focused | 90.9% | 9.1% | 175 |
| Aggressive Sigil User | 39.4% | 60.6% | 175 |
| Mostly Idle | 100.0% | 0.0% | 175 |

## 2. Star Accumulation

| Archetype | Score Mean | Score Median | Global Stars Mean |
|---|---|---|---|
| Casual | 2656 | 2674 | 20939 |
| Regular | 3926 | 4021 | 34444 |
| Hardcore | 6361 | 6300 | 71195 |
| Hoarder | 3692 | 3750 | 30826 |
| Early Locker | 3671 | 3634 | 27836 |
| Late Deployer | 3621 | 3493 | 37611 |
| Boost-Focused | 6033 | 6022 | 62308 |
| Star-Focused | 4440 | 4485 | 35396 |
| Aggressive Sigil User | 5301 | 5276 | 54000 |
| Mostly Idle | 2463 | 2492 | 18671 |

## 3. Sigil Tier Distribution

*(Per-player mean acquisition by tier, averaged across runs)*

| Archetype | T1 | T2 | T3 | T4 | T5 | T6 |
|---|---|---|---|---|---|---|
| Casual | 30.0 | 14.3 | 4.8 | 0.7 | 0.1 | 0.0 |
| Regular | 47.8 | 25.1 | 8.4 | 1.6 | 0.3 | 0.0 |
| Hardcore | 74.0 | 39.7 | 14.6 | 3.4 | 0.5 | 0.0 |
| Hoarder | 56.5 | 30.1 | 9.7 | 1.6 | 0.3 | 0.0 |
| Early Locker | 36.2 | 17.8 | 5.3 | 0.5 | 0.1 | 0.0 |
| Late Deployer | 43.6 | 24.1 | 9.1 | 1.9 | 0.3 | 0.0 |
| Boost-Focused | 65.0 | 36.7 | 13.2 | 2.8 | 0.6 | 0.0 |
| Star-Focused | 52.5 | 28.3 | 9.4 | 1.3 | 0.3 | 0.0 |
| Aggressive Sigil User | 61.2 | 32.9 | 12.4 | 2.6 | 0.4 | 0.0 |
| Mostly Idle | 21.4 | 10.5 | 2.9 | 0.3 | 0.0 | 0.0 |

## 4. Boost Usage

Boost-focused vs Regular score ratio: **1.51**

## 5. Ranking Concentration

- Sim B top-10% share (mean): **15.5%**
- Sim C final top-10% share (mean): **24.3%**

## 6. Archetype Outcome Spread

Overall mean of medians: **4329**


| Archetype | Mean Score | Ratio to Overall |
|---|---|---|
| Casual | 2746 | 0.63 |
| Regular | 4207 | 0.97 |
| Hardcore | 6541 | 1.51 |
| Hoarder | 3795 | 0.88 |
| Early Locker | 3683 | 0.85 |
| Late Deployer | 3621 | 0.84 |
| Boost-Focused | 6269 | 1.45 |
| Star-Focused | 4622 | 1.07 |
| Aggressive Sigil User | 5519 | 1.27 |
| Mostly Idle | 2290 | 0.53 |

## 7. Final Standing Distribution

- Total players: 1750
- Mean: 4217 | Median: 4051 | P10: 2383 | P90: 6250
- Locked-in mean: 3720 | Expired mean: 5201 | Gap: -1481

## 8. Dominant Strategies

| Combo | Mean Score | Ratio |
|---|---|---|
| Hardcore / EARLY | 6556 | 1.62 |
| Hardcore / MID | 6556 | 1.62 |
| Hardcore / LATE_ACTIVE | 6556 | 1.62 |
| Hardcore / BLACKOUT | 6556 | 1.62 |
| Boost-Focused / EARLY | 6214 | 1.54 |
| Boost-Focused / MID | 6214 | 1.54 |
| Boost-Focused / LATE_ACTIVE | 6214 | 1.54 |
| Boost-Focused / BLACKOUT | 6214 | 1.54 |

## 9. Hoarding vs Spending

Hoarder vs Regular score ratio: **0.90**

## 10. Phase-by-Phase Behavior

Grand total actions: 124049
Late-active engaged rate (mean): **70.0%**

| Phase | Actions | Share |
|---|---|---|
| EARLY | 49916 | 40.2% |
| MID | 47977 | 38.7% |
| LATE_ACTIVE | 25850 | 20.8% |
| BLACKOUT | 306 | 0.2% |

## 11. Cross-Seed Stability

Seeds: 5 | Threshold CV: 0.15

| Metric | CV | Status |
|---|---|---|
| lock_in_rate | 0.0297 | OK |
| mean_score | 0.0178 | OK |
| expiry_rate | 0.0564 | OK |

## 12. Star Pricing

Seeds: 5 | Price CV across seeds: **0.0000**
- Global min price: 32 | Global max price: 97
- Stuck at cap share: **0.0%** | Stuck at floor share: **0.0%**
- Combined stuck share: **0.0%**

## 13. Progression Pacing

Progression pacing data not available (no phase-end score snapshots).

## 14. Mechanic Attribution

**Per-archetype boost contribution:**

| Archetype | Boost Coin Share | Mean Ticks Boosted | Mean Ticks Frozen |
|---|---|---|---|
| Casual | 67.5% | 47479 | 0 |
| Regular | 67.7% | 64839 | 0 |
| Hardcore | 66.4% | 77250 | 2012 |
| Hoarder | 57.1% | 54385 | 0 |
| Early Locker | 70.8% | 43723 | 0 |
| Late Deployer | 64.4% | 66019 | 0 |
| Boost-Focused | 68.8% | 75627 | 500 |
| Star-Focused | 64.5% | 63758 | 0 |
| Aggressive Sigil User | 67.6% | 73869 | 0 |
| Mostly Idle | 68.9% | 33556 | 0 |

**Top-quartile analysis** (n=438):
- Top coin delta vs rest: 106019
- Boost share of coin delta: **67.8%**

