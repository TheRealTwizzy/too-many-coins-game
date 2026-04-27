# Baseline Analysis Summary

Generated: 2026-04-27T13:52:02+00:00
Sim B runs: 15 | Sim C runs: 6

## 1. Lock-In vs Expiry

- Overall lock-in rate: **59.9%** (n=1750)
- Overall natural expiry rate: **40.1%**

**Lock-in timing distribution:**
- EARLY: 0.0%
- MID: 27.2%
- LATE_ACTIVE: 25.5%
- BLACKOUT: 7.1%
- NONE: 40.1%

**By archetype:**
| Archetype | Lock-in Rate | Expiry Rate | Sample |
|---|---|---|---|
| Casual | 93.7% | 6.3% | 175 |
| Regular | 70.3% | 29.7% | 175 |
| Hardcore | 0.6% | 99.4% | 175 |
| Hoarder | 88.0% | 12.0% | 175 |
| Early Locker | 100.0% | 0.0% | 175 |
| Late Deployer | 15.4% | 84.6% | 175 |
| Boost-Focused | 12.0% | 88.0% | 175 |
| Star-Focused | 94.3% | 5.7% | 175 |
| Aggressive Sigil User | 24.6% | 75.4% | 175 |
| Mostly Idle | 100.0% | 0.0% | 175 |

## 2. Star Accumulation

| Archetype | Score Mean | Score Median | Global Stars Mean |
|---|---|---|---|
| Casual | 2715 | 2663 | 21268 |
| Regular | 3957 | 4017 | 35292 |
| Hardcore | 6564 | 6388 | 77073 |
| Hoarder | 3740 | 3693 | 30550 |
| Early Locker | 3620 | 3531 | 27448 |
| Late Deployer | 3535 | 3452 | 39396 |
| Boost-Focused | 6198 | 6070 | 69756 |
| Star-Focused | 4392 | 4481 | 34396 |
| Aggressive Sigil User | 5237 | 5235 | 56761 |
| Mostly Idle | 2446 | 2453 | 18543 |

## 3. Sigil Tier Distribution

*(Per-player mean acquisition by tier, averaged across runs)*

| Archetype | T1 | T2 | T3 | T4 | T5 | T6 |
|---|---|---|---|---|---|---|
| Casual | 30.5 | 14.6 | 4.9 | 0.7 | 0.1 | 0.0 |
| Regular | 47.5 | 25.3 | 8.6 | 1.7 | 0.3 | 0.0 |
| Hardcore | 74.6 | 40.1 | 14.8 | 3.5 | 0.5 | 0.0 |
| Hoarder | 56.1 | 29.9 | 9.7 | 1.5 | 0.3 | 0.0 |
| Early Locker | 35.4 | 17.3 | 5.1 | 0.5 | 0.1 | 0.0 |
| Late Deployer | 44.9 | 24.9 | 9.5 | 2.2 | 0.3 | 0.1 |
| Boost-Focused | 66.5 | 37.9 | 13.8 | 3.1 | 0.6 | 0.0 |
| Star-Focused | 52.7 | 28.2 | 9.4 | 1.3 | 0.3 | 0.0 |
| Aggressive Sigil User | 62.5 | 34.0 | 12.7 | 2.7 | 0.5 | 0.0 |
| Mostly Idle | 21.2 | 10.4 | 2.8 | 0.3 | 0.0 | 0.0 |

## 4. Boost Usage

Boost-focused vs Regular score ratio: **1.55**

## 5. Ranking Concentration

- Sim B top-10% share (mean): **15.8%**
- Sim C final top-10% share (mean): **24.3%**

## 6. Archetype Outcome Spread

Overall mean of medians: **4345**


| Archetype | Mean Score | Ratio to Overall |
|---|---|---|
| Casual | 2726 | 0.63 |
| Regular | 4181 | 0.96 |
| Hardcore | 6761 | 1.56 |
| Hoarder | 3660 | 0.84 |
| Early Locker | 3682 | 0.85 |
| Late Deployer | 3596 | 0.83 |
| Boost-Focused | 6401 | 1.47 |
| Star-Focused | 4566 | 1.05 |
| Aggressive Sigil User | 5501 | 1.27 |
| Mostly Idle | 2375 | 0.55 |

## 7. Final Standing Distribution

- Total players: 1750
- Mean: 4240 | Median: 3996 | P10: 2374 | P90: 6324
- Locked-in mean: 3557 | Expired mean: 5261 | Gap: -1704

## 8. Dominant Strategies

| Combo | Mean Score | Ratio |
|---|---|---|
| Hardcore / EARLY | 6769 | 1.65 |
| Hardcore / MID | 6769 | 1.65 |
| Hardcore / LATE_ACTIVE | 6769 | 1.65 |
| Hardcore / BLACKOUT | 6769 | 1.65 |
| Boost-Focused / EARLY | 6402 | 1.56 |
| Boost-Focused / MID | 6402 | 1.56 |
| Boost-Focused / LATE_ACTIVE | 6402 | 1.56 |
| Boost-Focused / BLACKOUT | 6402 | 1.56 |

## 9. Hoarding vs Spending

Hoarder vs Regular score ratio: **0.88**

## 10. Phase-by-Phase Behavior

Grand total actions: 127698
Late-active engaged rate (mean): **69.6%**

| Phase | Actions | Share |
|---|---|---|
| EARLY | 51360 | 40.2% |
| MID | 47767 | 37.4% |
| LATE_ACTIVE | 28557 | 22.4% |
| BLACKOUT | 14 | 0.0% |

## 11. Cross-Seed Stability

Seeds: 5 | Threshold CV: 0.15

| Metric | CV | Status |
|---|---|---|
| lock_in_rate | 0.0181 | OK |
| mean_score | 0.0098 | OK |
| expiry_rate | 0.0264 | OK |

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
| Casual | 68.0% | 52387 | 0 |
| Regular | 73.9% | 81239 | 0 |
| Hardcore | 79.1% | 116397 | 1997 |
| Hoarder | 63.8% | 67539 | 0 |
| Early Locker | 78.1% | 57567 | 0 |
| Late Deployer | 70.6% | 79830 | 0 |
| Boost-Focused | 79.7% | 115037 | 478 |
| Star-Focused | 69.2% | 76550 | 0 |
| Aggressive Sigil User | 77.4% | 105635 | 0 |
| Mostly Idle | 68.4% | 35670 | 0 |

**Top-quartile analysis** (n=438):
- Top coin delta vs rest: 113427
- Boost share of coin delta: **83.9%**

