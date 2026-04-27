# Baseline Analysis Summary

Generated: 2026-04-27T16:50:52+00:00
Sim B runs: 15 | Sim C runs: 6

## 1. Lock-In vs Expiry

- Overall lock-in rate: **66.4%** (n=1750)
- Overall natural expiry rate: **33.6%**

**Lock-in timing distribution:**
- EARLY: 0.0%
- MID: 26.8%
- LATE_ACTIVE: 33.8%
- BLACKOUT: 5.8%
- NONE: 33.6%

**By archetype:**
| Archetype | Lock-in Rate | Expiry Rate | Sample |
|---|---|---|---|
| Casual | 92.6% | 7.4% | 175 |
| Regular | 74.3% | 25.7% | 175 |
| Hardcore | 14.3% | 85.7% | 175 |
| Hoarder | 85.1% | 14.9% | 175 |
| Early Locker | 100.0% | 0.0% | 175 |
| Late Deployer | 33.1% | 66.9% | 175 |
| Boost-Focused | 34.9% | 65.1% | 175 |
| Star-Focused | 90.9% | 9.1% | 175 |
| Aggressive Sigil User | 38.9% | 61.1% | 175 |
| Mostly Idle | 100.0% | 0.0% | 175 |

## 2. Star Accumulation

| Archetype | Score Mean | Score Median | Global Stars Mean |
|---|---|---|---|
| Casual | 2650 | 2674 | 20963 |
| Regular | 3924 | 4021 | 34427 |
| Hardcore | 6367 | 6308 | 71245 |
| Hoarder | 3705 | 3750 | 30826 |
| Early Locker | 3671 | 3634 | 27836 |
| Late Deployer | 3621 | 3493 | 37601 |
| Boost-Focused | 6033 | 6022 | 62312 |
| Star-Focused | 4446 | 4485 | 35439 |
| Aggressive Sigil User | 5290 | 5276 | 54055 |
| Mostly Idle | 2469 | 2510 | 18714 |

## 3. Sigil Tier Distribution

*(Per-player mean acquisition by tier, averaged across runs)*

| Archetype | T1 | T2 | T3 | T4 | T5 | T6 |
|---|---|---|---|---|---|---|
| Casual | 30.0 | 14.3 | 4.8 | 0.7 | 0.1 | 0.0 |
| Regular | 47.7 | 25.1 | 8.4 | 1.6 | 0.3 | 0.0 |
| Hardcore | 74.0 | 39.7 | 14.6 | 3.4 | 0.5 | 0.0 |
| Hoarder | 56.5 | 30.1 | 9.7 | 1.6 | 0.3 | 0.0 |
| Early Locker | 36.2 | 17.8 | 5.3 | 0.5 | 0.1 | 0.0 |
| Late Deployer | 43.6 | 24.1 | 9.1 | 1.9 | 0.3 | 0.0 |
| Boost-Focused | 65.0 | 36.7 | 13.2 | 2.8 | 0.6 | 0.0 |
| Star-Focused | 52.5 | 28.3 | 9.4 | 1.3 | 0.3 | 0.0 |
| Aggressive Sigil User | 61.2 | 32.8 | 12.4 | 2.6 | 0.4 | 0.0 |
| Mostly Idle | 21.4 | 10.5 | 2.9 | 0.3 | 0.0 | 0.0 |

## 4. Boost Usage

Boost-focused vs Regular score ratio: **1.51**

## 5. Ranking Concentration

- Sim B top-10% share (mean): **15.5%**
- Sim C final top-10% share (mean): **24.3%**

## 6. Archetype Outcome Spread

Overall mean of medians: **4327**


| Archetype | Mean Score | Ratio to Overall |
|---|---|---|
| Casual | 2745 | 0.63 |
| Regular | 4203 | 0.97 |
| Hardcore | 6541 | 1.51 |
| Hoarder | 3795 | 0.88 |
| Early Locker | 3683 | 0.85 |
| Late Deployer | 3615 | 0.84 |
| Boost-Focused | 6269 | 1.45 |
| Star-Focused | 4617 | 1.07 |
| Aggressive Sigil User | 5509 | 1.27 |
| Mostly Idle | 2290 | 0.53 |

## 7. Final Standing Distribution

- Total players: 1750
- Mean: 4218 | Median: 4051 | P10: 2387 | P90: 6248
- Locked-in mean: 3721 | Expired mean: 5200 | Gap: -1479

## 8. Dominant Strategies

| Combo | Mean Score | Ratio |
|---|---|---|
| Hardcore / EARLY | 6563 | 1.62 |
| Hardcore / MID | 6563 | 1.62 |
| Hardcore / LATE_ACTIVE | 6563 | 1.62 |
| Hardcore / BLACKOUT | 6563 | 1.62 |
| Boost-Focused / EARLY | 6214 | 1.54 |
| Boost-Focused / MID | 6214 | 1.54 |
| Boost-Focused / LATE_ACTIVE | 6214 | 1.54 |
| Boost-Focused / BLACKOUT | 6214 | 1.54 |

## 9. Hoarding vs Spending

Hoarder vs Regular score ratio: **0.90**

## 10. Phase-by-Phase Behavior

Grand total actions: 124022
Late-active engaged rate (mean): **70.0%**

| Phase | Actions | Share |
|---|---|---|
| EARLY | 49916 | 40.2% |
| MID | 47977 | 38.7% |
| LATE_ACTIVE | 25818 | 20.8% |
| BLACKOUT | 311 | 0.2% |

## 11. Cross-Seed Stability

Seeds: 5 | Threshold CV: 0.15

| Metric | CV | Status |
|---|---|---|
| lock_in_rate | 0.0280 | OK |
| mean_score | 0.0168 | OK |
| expiry_rate | 0.0528 | OK |

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
| Casual | 67.5% | 47436 | 0 |
| Regular | 67.7% | 64746 | 0 |
| Hardcore | 66.4% | 77260 | 2009 |
| Hoarder | 57.1% | 54387 | 0 |
| Early Locker | 70.8% | 43723 | 0 |
| Late Deployer | 64.4% | 66011 | 0 |
| Boost-Focused | 68.8% | 75666 | 502 |
| Star-Focused | 64.4% | 63813 | 0 |
| Aggressive Sigil User | 67.6% | 73860 | 0 |
| Mostly Idle | 68.9% | 33556 | 0 |

**Top-quartile analysis** (n=438):
- Top coin delta vs rest: 106078
- Boost share of coin delta: **67.8%**

