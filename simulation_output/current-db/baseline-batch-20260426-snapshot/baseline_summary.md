# Baseline Analysis Summary

Generated: 2026-04-26T17:36:48+00:00
Sim B runs: 15 | Sim C runs: 6

## 1. Lock-In vs Expiry

- Overall lock-in rate: **72.7%** (n=1750)
- Overall natural expiry rate: **27.3%**

**Lock-in timing distribution:**
- EARLY: 0.0%
- MID: 27.1%
- LATE_ACTIVE: 42.1%
- BLACKOUT: 3.4%
- NONE: 27.3%

**By archetype:**
| Archetype | Lock-in Rate | Expiry Rate | Sample |
|---|---|---|---|
| Casual | 92.0% | 8.0% | 175 |
| Regular | 88.6% | 11.4% | 175 |
| Hardcore | 42.9% | 57.1% | 175 |
| Hoarder | 98.3% | 1.7% | 175 |
| Early Locker | 100.0% | 0.0% | 175 |
| Late Deployer | 23.4% | 76.6% | 175 |
| Boost-Focused | 12.0% | 88.0% | 175 |
| Star-Focused | 99.4% | 0.6% | 175 |
| Aggressive Sigil User | 70.9% | 29.1% | 175 |
| Mostly Idle | 99.4% | 0.6% | 175 |

## 2. Star Accumulation

| Archetype | Score Mean | Score Median | Global Stars Mean |
|---|---|---|---|
| Casual | 13938 | 13683 | 109033 |
| Regular | 18015 | 18009 | 145326 |
| Hardcore | 22205 | 21634 | 218162 |
| Hoarder | 13293 | 13483 | 101446 |
| Early Locker | 17203 | 17355 | 130450 |
| Late Deployer | 19830 | 19748 | 212015 |
| Boost-Focused | 17728 | 17727 | 199930 |
| Star-Focused | 24431 | 25025 | 185714 |
| Aggressive Sigil User | 19089 | 19161 | 163916 |
| Mostly Idle | 13789 | 14396 | 104715 |

## 3. Sigil Tier Distribution

*(Per-player mean acquisition by tier, averaged across runs)*

| Archetype | T1 | T2 | T3 | T4 | T5 | T6 |
|---|---|---|---|---|---|---|
| Casual | 419.7 | 217.9 | 69.5 | 10.8 | 1.9 | 0.1 |
| Regular | 613.1 | 322.9 | 109.0 | 19.2 | 3.7 | 0.2 |
| Hardcore | 983.9 | 529.6 | 194.3 | 40.1 | 7.1 | 0.5 |
| Hoarder | 692.5 | 361.2 | 118.7 | 17.2 | 3.3 | 0.2 |
| Early Locker | 458.6 | 223.7 | 62.6 | 6.0 | 1.0 | 0.0 |
| Late Deployer | 622.7 | 343.0 | 134.7 | 31.5 | 5.5 | 0.5 |
| Boost-Focused | 947.6 | 521.3 | 196.2 | 42.3 | 7.7 | 0.8 |
| Star-Focused | 675.5 | 354.1 | 119.2 | 17.7 | 3.2 | 0.1 |
| Aggressive Sigil User | 786.5 | 420.9 | 148.8 | 27.5 | 4.7 | 0.5 |
| Mostly Idle | 284.4 | 139.2 | 38.9 | 3.6 | 0.7 | 0.0 |

## 4. Boost Usage

Boost-focused vs Regular score ratio: **0.99**

## 5. Ranking Concentration

- Sim B top-10% share (mean): **14.7%**
- Sim C final top-10% share (mean): **22.3%**

## 6. Archetype Outcome Spread

Overall mean of medians: **17480**


| Archetype | Mean Score | Ratio to Overall |
|---|---|---|
| Casual | 13135 | 0.75 |
| Regular | 17716 | 1.01 |
| Hardcore | 21496 | 1.23 |
| Hoarder | 12753 | 0.73 |
| Early Locker | 16605 | 0.95 |
| Late Deployer | 19706 | 1.13 |
| Boost-Focused | 17479 | 1.00 |
| Star-Focused | 23735 | 1.36 |
| Aggressive Sigil User | 18418 | 1.05 |
| Mostly Idle | 13759 | 0.79 |

## 7. Final Standing Distribution

- Total players: 1750
- Mean: 17952 | Median: 18390 | P10: 11198 | P90: 23662
- Locked-in mean: 17703 | Expired mean: 18614 | Gap: -911

## 8. Dominant Strategies

No archetype+phase combos exceed 1.5x median threshold.

## 9. Hoarding vs Spending

Hoarder vs Regular score ratio: **0.72**

## 10. Phase-by-Phase Behavior

Grand total actions: 1459466
Late-active engaged rate (mean): **70.7%**

| Phase | Actions | Share |
|---|---|---|
| EARLY | 550749 | 37.7% |
| MID | 595997 | 40.8% |
| LATE_ACTIVE | 312462 | 21.4% |
| BLACKOUT | 258 | 0.0% |

## 11. Cross-Seed Stability

Seeds: 5 | Threshold CV: 0.15

| Metric | CV | Status |
|---|---|---|
| lock_in_rate | 0.0193 | OK |
| mean_score | 0.0107 | OK |
| expiry_rate | 0.0473 | OK |

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
| Casual | 99.9% | 133360 | 0 |
| Regular | 99.9% | 149526 | 0 |
| Hardcore | 99.9% | 191017 | 1000 |
| Hoarder | 98.8% | 125151 | 0 |
| Early Locker | 99.9% | 95282 | 0 |
| Late Deployer | 99.9% | 206372 | 0 |
| Boost-Focused | 100.0% | 216538 | 0 |
| Star-Focused | 99.9% | 143629 | 11490 |
| Aggressive Sigil User | 99.9% | 169682 | 0 |
| Mostly Idle | 99.8% | 95294 | 0 |

**Top-quartile analysis** (n=438):
- Top coin delta vs rest: 96909
- Boost share of coin delta: **100.4%**

