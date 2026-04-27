# Baseline Analysis Summary

Generated: 2026-04-27T09:57:55+00:00
Sim B runs: 15 | Sim C runs: 6

## 1. Lock-In vs Expiry

- Overall lock-in rate: **58.7%** (n=1750)
- Overall natural expiry rate: **41.3%**

**Lock-in timing distribution:**
- EARLY: 0.0%
- MID: 24.8%
- LATE_ACTIVE: 26.4%
- BLACKOUT: 7.5%
- NONE: 41.3%

**By archetype:**
| Archetype | Lock-in Rate | Expiry Rate | Sample |
|---|---|---|---|
| Casual | 95.4% | 4.6% | 175 |
| Regular | 64.6% | 35.4% | 175 |
| Hardcore | 0.6% | 99.4% | 175 |
| Hoarder | 82.9% | 17.1% | 175 |
| Early Locker | 100.0% | 0.0% | 175 |
| Late Deployer | 18.3% | 81.7% | 175 |
| Boost-Focused | 12.0% | 88.0% | 175 |
| Star-Focused | 92.6% | 7.4% | 175 |
| Aggressive Sigil User | 20.6% | 79.4% | 175 |
| Mostly Idle | 100.0% | 0.0% | 175 |

## 2. Star Accumulation

| Archetype | Score Mean | Score Median | Global Stars Mean |
|---|---|---|---|
| Casual | 3465 | 3401 | 26995 |
| Regular | 5365 | 5502 | 49359 |
| Hardcore | 8738 | 8654 | 102346 |
| Hoarder | 4064 | 4062 | 34501 |
| Early Locker | 4835 | 4695 | 36664 |
| Late Deployer | 4595 | 4537 | 50929 |
| Boost-Focused | 8617 | 8632 | 96863 |
| Star-Focused | 5227 | 5312 | 41297 |
| Aggressive Sigil User | 7154 | 7243 | 78527 |
| Mostly Idle | 2894 | 2870 | 21942 |

## 3. Sigil Tier Distribution

*(Per-player mean acquisition by tier, averaged across runs)*

| Archetype | T1 | T2 | T3 | T4 | T5 | T6 |
|---|---|---|---|---|---|---|
| Casual | 28.9 | 14.1 | 4.7 | 0.6 | 0.1 | 0.0 |
| Regular | 44.8 | 23.6 | 8.0 | 1.6 | 0.3 | 0.0 |
| Hardcore | 62.6 | 33.5 | 12.4 | 2.8 | 0.4 | 0.0 |
| Hoarder | 54.4 | 28.9 | 9.6 | 1.6 | 0.3 | 0.0 |
| Early Locker | 33.2 | 16.4 | 5.1 | 0.6 | 0.1 | 0.0 |
| Late Deployer | 41.1 | 22.6 | 8.4 | 1.9 | 0.2 | 0.1 |
| Boost-Focused | 55.2 | 31.2 | 11.5 | 2.5 | 0.5 | 0.0 |
| Star-Focused | 50.5 | 27.3 | 9.3 | 1.4 | 0.2 | 0.0 |
| Aggressive Sigil User | 54.8 | 29.9 | 11.5 | 2.4 | 0.4 | 0.0 |
| Mostly Idle | 20.9 | 10.5 | 2.9 | 0.3 | 0.0 | 0.0 |

## 4. Boost Usage

Boost-focused vs Regular score ratio: **1.60**

## 5. Ranking Concentration

- Sim B top-10% share (mean): **16.6%**
- Sim C final top-10% share (mean): **24.3%**

## 6. Archetype Outcome Spread

Overall mean of medians: **5591**


| Archetype | Mean Score | Ratio to Overall |
|---|---|---|
| Casual | 3469 | 0.62 |
| Regular | 5562 | 0.99 |
| Hardcore | 8954 | 1.60 |
| Hoarder | 4107 | 0.73 |
| Early Locker | 4853 | 0.87 |
| Late Deployer | 4609 | 0.82 |
| Boost-Focused | 8837 | 1.58 |
| Star-Focused | 5362 | 0.96 |
| Aggressive Sigil User | 7355 | 1.32 |
| Mostly Idle | 2806 | 0.50 |

## 7. Final Standing Distribution

- Total players: 1750
- Mean: 5495 | Median: 5122 | P10: 2982 | P90: 8662
- Locked-in mean: 4362 | Expired mean: 7105 | Gap: -2743

## 8. Dominant Strategies

| Combo | Mean Score | Ratio |
|---|---|---|
| Hardcore / EARLY | 8927 | 1.75 |
| Hardcore / MID | 8927 | 1.75 |
| Hardcore / LATE_ACTIVE | 8927 | 1.75 |
| Hardcore / BLACKOUT | 8927 | 1.75 |
| Boost-Focused / EARLY | 8834 | 1.73 |
| Boost-Focused / MID | 8834 | 1.73 |
| Boost-Focused / LATE_ACTIVE | 8834 | 1.73 |
| Boost-Focused / BLACKOUT | 8834 | 1.73 |

## 9. Hoarding vs Spending

Hoarder vs Regular score ratio: **0.74**

## 10. Phase-by-Phase Behavior

Grand total actions: 115030
Late-active engaged rate (mean): **72.3%**

| Phase | Actions | Share |
|---|---|---|
| EARLY | 46612 | 40.5% |
| MID | 43488 | 37.8% |
| LATE_ACTIVE | 24918 | 21.7% |
| BLACKOUT | 12 | 0.0% |

## 11. Cross-Seed Stability

Seeds: 5 | Threshold CV: 0.15

| Metric | CV | Status |
|---|---|---|
| lock_in_rate | 0.0339 | OK |
| mean_score | 0.0106 | OK |
| expiry_rate | 0.0470 | OK |

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
| Casual | 91.3% | 85147 | 0 |
| Regular | 93.9% | 126011 | 0 |
| Hardcore | 95.6% | 158502 | 1601 |
| Hoarder | 88.5% | 108689 | 0 |
| Early Locker | 95.0% | 86445 | 9 |
| Late Deployer | 92.6% | 126267 | 0 |
| Boost-Focused | 96.1% | 158103 | 704 |
| Star-Focused | 91.7% | 117760 | 0 |
| Aggressive Sigil User | 95.2% | 150349 | 4 |
| Mostly Idle | 90.7% | 62914 | 0 |

**Top-quartile analysis** (n=438):
- Top coin delta vs rest: 157105
- Boost share of coin delta: **98.1%**

